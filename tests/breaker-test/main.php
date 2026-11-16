<?php

/**
 * 熔断降级的语义测试。
 *
 * 分两部分：
 *   A. `CircuitBreaker` 本身（纯逻辑，喂假时间，不碰调度器）——三态迁移、探针、
 *      冷却翻倍、探针位归还。
 *   B. 接进 `Shard\Router` 之后（真调度器 + 真超时）——熔断要多久、熔断后是不是
 *      立刻返回、业务错误会不会误熔断、降级值走哪条路径、探针能不能把它救回来。
 *
 * 真正"节点坏了不影响别的节点"在 `breaker-live` 里（三个进程，一个半死）。
 */

use TSF\Breaker\CircuitBreaker;
use TSF\Cluster\Addr;
use TSF\Cluster\Cluster;
use TSF\Discovery\FileRegistry;
use TSF\Discovery\NodeInfo;
use TSF\Rpc\Client;
use TSF\Rpc\Rpc;
use TSF\Rpc\Router as RpcRouter;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;
use TSF\Shard\Router;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/**
 * 半死的服务：**收到消息但从不回复**。
 *
 * 这不是"进程挂了"，而是"进程活着、心跳还在跳、socket 还连着，但工作循环卡住了"——
 * 死循环、GC 卡死、磁盘 IO 阻塞都是这个样子。注册表完全看不出异常，
 * 只有熔断器能发现。
 */
final class SilentService extends Service
{
    private RpcRouter $rpc;

    public int $received = 0;

    public bool $healthy = false;

    public function __construct()
    {
        $this->rpc = new RpcRouter();
        $this->rpc->on("echo", function (string $b, Message $m): string {
            return "echo:" . $b;
        });
        $this->rpc->on("nosuchrole", function (string $b, Message $m): string {
            throw new \RuntimeException("no such role: " . $b);
        });
    }

    public function onMessage(Message $m): void
    {
        $this->received++;
        if (!$this->healthy) {
            return;   // 卡住了：不处理、不回复
        }
        $this->rpc->handle($m);
    }

    public function setHealthy(bool $v): void
    {
        $this->healthy = $v;
    }
}

function bootScheduler(int $nodeId = 1): Scheduler
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $path = "/tmp/tsf_bk_probe_" . $nodeId . ".sock";
    if (file_exists($path)) {
        unlink($path);
    }
    $c = new Cluster($nodeId, $path);
    $c->listen();
    $s->setCluster($c);
    return $s;
}

function freshDir(string $name): string
{
    $dir = "/tmp/tsf_bk_" . $name;
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            $p = $dir . "/" . $f;
            if (is_file($p)) {
                unlink($p);
            }
        }
    }
    return $dir;
}

function drain(Scheduler $s, int $times = 8): void
{
    for ($i = 0; $i < $times; $i++) {
        $s->tick();
    }
}

// ───────────────────────── A. 纯逻辑 ─────────────────────────

function caseStates(): void
{
    echo "== [1] 三态迁移 ==" . PHP_EOL;

    $b = new CircuitBreaker(3, 2000, 30000);
    $t = 1000;

    T::eq($b->allow(1, $t), true, "没见过的目标先信任");
    T::eq($b->state(1, $t), CircuitBreaker::CLOSED, "初始是 CLOSED");

    $b->fail(1, $t);
    $b->fail(1, $t);
    T::eq($b->state(1, $t), CircuitBreaker::CLOSED, "失败 2 次（阈值 3）仍是 CLOSED");
    T::eq($b->allow(1, $t), true, "还能发");

    $b->fail(1, $t);
    T::eq($b->state(1, $t), CircuitBreaker::OPEN, "第 3 次失败 → OPEN");
    T::eq($b->allow(1, $t), false, "OPEN 之后拒绝");
    T::eq($b->allow(1, $t), false, "继续拒绝");

    $st = $b->stats();
    T::eq((int) $st["trips"], 1, "熔断次数 1");
    T::eq((int) $st["short_circuited"], 2, "短路计数 2");

    // 成功一次立刻恢复
    $b->ok(1);
    T::eq($b->state(1, $t), CircuitBreaker::CLOSED, "成功 → 回到 CLOSED");
    T::eq($b->fails(1), 0, "连续失败清零");
    T::eq($b->allow(1, $t), true, "恢复后放行");
}

function caseProbe(): void
{
    echo "== [2] 冷却与探针 ==" . PHP_EOL;

    $b = new CircuitBreaker(1, 2000, 30000);
    $t = 10000;
    $b->fail(1, $t);   // 阈值 1，立刻 OPEN
    T::eq($b->state(1, $t), CircuitBreaker::OPEN, "已熔断");

    T::eq($b->state(1, $t + 1999), CircuitBreaker::OPEN, "冷却没到（1999ms）仍报 OPEN");
    T::eq($b->allow(1, $t + 1999), false, "冷却没到不放行");

    T::eq($b->state(1, $t + 2000), CircuitBreaker::HALF_OPEN, "冷却到点报 HALF_OPEN");
    T::eq($b->allow(1, $t + 2000), true, "放第一个探针");
    T::eq($b->state(1, $t + 2000), CircuitBreaker::HALF_OPEN, "探针在飞时是 HALF_OPEN");
    T::eq($b->allow(1, $t + 2000), false, "**只放一个**：第二个请求拒绝");
    T::eq($b->allow(1, $t + 5000), false, "探针没回来之前，后面的一直拒绝");

    // 探针成功 → 恢复
    $b->ok(1);
    T::eq($b->state(1, $t + 5000), CircuitBreaker::CLOSED, "探针成功 → CLOSED");
    T::eq($b->cooldown(1), 2000, "冷却复位到基准 2000ms");
    T::eq($b->allow(1, $t + 5000), true, "恢复后正常放行");
}

function caseBackoff(): void
{
    echo "== [3] 探针失败 → 冷却翻倍（封顶）==" . PHP_EOL;

    $b = new CircuitBreaker(1, 1000, 4000);
    $t = 0;
    $b->fail(1, $t);
    T::eq($b->cooldown(1), 1000, "首次冷却 1000ms");

    $t = 1000;
    T::eq($b->allow(1, $t), true, "第一次探针");
    $b->fail(1, $t);
    T::eq($b->state(1, $t), CircuitBreaker::OPEN, "探针失败 → 重新 OPEN");
    T::eq($b->cooldown(1), 2000, "冷却翻倍到 2000ms");
    T::eq($b->allow(1, $t + 1500), false, "1500ms 还不够（要 2000ms）");

    $t = $t + 2000;
    T::eq($b->allow(1, $t), true, "第二次探针");
    $b->fail(1, $t);
    T::eq($b->cooldown(1), 4000, "翻倍到 4000ms（上限）");

    $t = $t + 4000;
    T::eq($b->allow(1, $t), true, "第三次探针");
    $b->fail(1, $t);
    T::eq($b->cooldown(1), 4000, "**封顶**：不会超过 4000ms");

    // 一次成功把冷却打回基准，别让偶发故障永久惩罚它
    $t = $t + 4000;
    $b->allow(1, $t);
    $b->ok(1);
    T::eq($b->cooldown(1), 1000, "成功后冷却回到 1000ms");
}

function caseNoExtend(): void
{
    echo "== [4] 熔断后的迟到失败不延长冷却 ==" . PHP_EOL;

    // 场景：200 个请求在飞，节点此刻死掉，它们会在 200ms 后陆续超时回来。
    // 如果每次失败都刷新 openedAt，冷却会被"在飞数量 × 超时时长"往后推，
    // 节点越多、负载越高，越不可能恢复
    $b = new CircuitBreaker(3, 2000, 30000);
    $b->fail(1, 0);
    $b->fail(1, 0);
    $b->fail(1, 0);
    T::eq($b->state(1, 1500), CircuitBreaker::OPEN, "熔断于 t=0");

    for ($i = 0; $i < 100; $i++) {
        $b->fail(1, 100 + $i);
    }
    T::eq($b->state(1, 1900), CircuitBreaker::OPEN, "仍然 OPEN（还没到 2000）");
    T::eq($b->state(1, 2000), CircuitBreaker::HALF_OPEN, "t=2000 就到期，没被 100 次迟到失败推迟");
    T::eq((int) $b->stats()["trips"], 1, "没有重复计熔断");
}

function caseRelease(): void
{
    echo "== [5] 探针没结论时还回探针位 ==" . PHP_EOL;

    // 探针发出去了但我们自己发不动（待发表满），既不能算对端健康，
    // 也不能算它坏——但探针位必须放开，否则这个节点永远等不到下一次探活
    $b = new CircuitBreaker(1, 1000, 30000);
    $t = 0;
    $b->fail(1, $t);
    $t = 1000;
    T::eq($b->allow(1, $t), true, "放探针");
    T::eq($b->allow(1, $t), false, "探针被占住");

    $b->release(1);
    T::eq($b->state(1, $t), CircuitBreaker::HALF_OPEN, "状态没变");
    T::eq($b->allow(1, $t), true, "还回之后立刻能再探（不用等冷却）");

    $b->release(1);
    $b->ok(1);
    T::eq($b->state(1, $t), CircuitBreaker::CLOSED, "这次成功 → 恢复");

    // 不在 HALF_OPEN 时 release 不该有副作用
    $b2 = new CircuitBreaker(1, 1000, 30000);
    $b2->release(9);
    T::eq($b2->state(9, 0), CircuitBreaker::CLOSED, "对未知目标 release 无副作用");
}

function caseIsolation(): void
{
    echo "== [6] 按目标隔离 ==" . PHP_EOL;

    $b = new CircuitBreaker(1, 5000, 30000);
    $b->fail(2, 0);
    T::eq($b->state(2, 0), CircuitBreaker::OPEN, "节点 2 熔断");
    T::eq($b->state(3, 0), CircuitBreaker::CLOSED, "节点 3 不受影响");
    T::eq($b->allow(3, 0), true, "节点 3 照常放行");

    $open = $b->openKeys(0);
    T::eq(count($open), 1, "openKeys 只列坏的那个");
    T::eq($open[0], 2, "是节点 2");

    $b->forget(2);
    T::eq($b->state(2, 0), CircuitBreaker::CLOSED, "forget 之后回到 CLOSED");
    T::eq(count($b->openKeys(0)), 0, "openKeys 空了");
}

// ─────────────────── B. 错误分类（熔断的判据）───────────────────

function caseClassify(): void
{
    echo "== [7] 什么算对端的错 ==" . PHP_EOL;

    T::eq(Rpc::blamesPeer(Rpc::ERR_TIMEOUT), true, "timeout 算对端");
    T::eq(Rpc::blamesPeer(Rpc::ERR_BAD_RESPONSE), true, "bad-response 算对端（协议不符）");

    T::eq(Rpc::blamesPeer(Rpc::ERR_TOO_MANY_PENDING), false, "**too-many-pending 不算**（我们自己满了）");
    T::eq(Rpc::blamesPeer(Rpc::ERR_ENCODE), false, "encode-failed 不算（我们的 codec）");
    T::eq(Rpc::blamesPeer(Rpc::ERR_BAD_ADDR), false, "bad-addr 不算（我们的地址）");

    T::eq(Rpc::blamesPeer("no such role"), false, "**业务错误不算**（能回话就是活着）");
    T::eq(Rpc::blamesPeer(""), false, "没有错误");

    T::eq(Rpc::isLocalError(Rpc::ERR_TIMEOUT), true, "timeout 是本地产生的码");
    T::eq(Rpc::isLocalError("no such role"), false, "业务错误不是本地码");
}

// ─────────────────── C. 接进 Router ───────────────────

/** 起一套 Router：目标节点 1 上挂一个 SilentService */
function bootRouter(bool $healthy, int $threshold, int $cooldown, int $maxPerTarget = 64): array
{
    $s = bootScheduler(1);
    $svcAddr = $s->spawnOf(function (): SilentService {
        return new SilentService();
    });
    $svc = $s->serviceOf($svcAddr);
    if (!($svc instanceof SilentService)) {
        throw new \RuntimeException("SilentService 没起来");
    }
    $svc->setHealthy($healthy);

    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(100);   // 100ms 超时，故障判定要快
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        throw new \RuntimeException("Client 没起来");
    }

    $dir = freshDir("reg");
    $reg = new FileRegistry($dir, 30000);
    $reg->register(new NodeInfo(1, "/tmp/tsf_bk_probe_1.sock", "json", [], Addr::id($svcAddr)));

    $breaker = new CircuitBreaker($threshold, $cooldown, 30000);
    $router = new Router($reg, $rpc, 1, 30000, 150, 1000, $breaker, $maxPerTarget);

    return [$s, $router, $svc, $breaker];
}

/** 发一次调用并推到回调结束，返回 [resp, err, 耗时毫秒] */
function callOnce(Scheduler $s, Router $router, int $uid, string $method = "echo", int $budget = 400): array
{
    $resp = null;
    $err = "";
    $done = 0;
    $t0 = hrtime(true);
    $router->call($uid, $method, "u" . $uid, function (?string $r, string $e) use (&$resp, &$err, &$done): void {
        $resp = $r;
        $err = $e;
        $done++;
    });
    $spin = 0;
    while ($done === 0 && $spin < $budget) {
        $s->tick();
        usleep(1000);
        $spin++;
    }
    $ms = (hrtime(true) - $t0) / 1000000.0;
    return [$resp, $err, $ms];
}

function caseTripFast(): void
{
    echo "== [8] 半死节点：多久被摘掉、摘掉后多快 ==" . PHP_EOL;

    [$s, $router, $svc, $breaker] = bootRouter(false, 3, 2000);

    $tripMs = 0.0;
    $fails = 0;
    for ($i = 1; $i <= 3; $i++) {
        $r = callOnce($s, $router, 100 + $i);
        if ($r[1] === Rpc::ERR_TIMEOUT) {
            $fails++;
        }
        $tripMs += $r[2];
        echo "  第 " . $i . " 次: err=" . $r[1] . " 耗时=" . number_format($r[2], 1) . "ms" . PHP_EOL;
    }
    T::eq($fails, 3, "前 3 次都是超时（对端收到但没回）");
    T::eq($breaker->state(1, Scheduler::nowMs()), CircuitBreaker::OPEN, "3 次超时后节点被熔断");
    T::ok($tripMs < 5000, "**从第一次失败到摘除 < 5s**（验收线）", number_format($tripMs, 0) . "ms");

    // 摘掉之后：不再等超时，立刻返回
    $r = callOnce($s, $router, 200);
    T::eq($r[1], "circuit-open", "熔断后返回 circuit-open");
    T::ok($r[2] < 5.0, "**熔断后立刻返回，不再等 100ms 超时**", number_format($r[2], 2) . "ms");

    $st = $router->stats();
    T::eq((int) $st["fast_open"], 1, "统计里记了一次快速失败");
    T::eq((int) $st["codec"], 0, "熔断没被误记成 codec 问题");

    $svc->setHealthy(true);
    drain($s);
}

function caseFallback(): void
{
    echo "== [9] 降级 ==" . PHP_EOL;

    [$s, $router, $svc, $breaker] = bootRouter(false, 3, 2000);
    for ($i = 0; $i < 3; $i++) {
        callOnce($s, $router, 300 + $i);
    }
    T::eq($breaker->state(1, Scheduler::nowMs()), CircuitBreaker::OPEN, "已熔断");

    // 有降级：返回兜底值，按成功回调
    $resp = "";
    $err = "x";
    $router->call(301, "echo", "u301", function (?string $r, string $e) use (&$resp, &$err): void {
        $resp = $r === null ? "<null>" : $r;
        $err = $e;
    }, 0, function (int $uid, string $method): ?string {
        return "[degraded] " . $method . ":" . $uid;
    });
    T::eq($err, "", "降级成功 → err 为空（当成功）");
    T::eq($resp, "[degraded] echo:301", "拿到兜底值");

    // 降级返回 null：原样报错
    $resp2 = "";
    $err2 = "x";
    $router->call(302, "echo", "u302", function (?string $r, string $e) use (&$resp2, &$err2): void {
        $resp2 = $r === null ? "<null>" : $r;
        $err2 = $e;
    }, 0, function (int $uid, string $method): ?string {
        return null;
    });
    T::eq($err2, "circuit-open", "降级返回 null → 还是报 circuit-open");
    T::eq($resp2, "<null>", "resp 为空");

    $st = $router->stats();
    T::eq((int) $st["degraded"], 1, "降级计数 1");
    T::eq((int) $st["fast_open"], 2, "快速失败 2 次（两次都在熔断中）");
}

function caseBusinessError(): void
{
    echo "== [10] 业务错误不能熔断 ==" . PHP_EOL;

    // 节点健康但业务报错 —— 这是最容易把熔断写错的地方：
    // 一律算失败的话，某个活动批量查不存在的角色就会把整台服熔断
    [$s, $router, $svc, $breaker] = bootRouter(true, 3, 2000);

    $errs = 0;
    for ($i = 1; $i <= 6; $i++) {
        // 这个 handler 每次都抛异常 → 远端回业务错误
        $r = callOnce($s, $router, 400 + $i, "nosuchrole");
        if ($r[1] !== "" && $r[1] !== "circuit-open") {
            $errs++;
        }
    }
    T::eq($errs, 6, "6 次都是业务错误");
    T::eq($breaker->state(1, Scheduler::nowMs()), CircuitBreaker::CLOSED, "**熔断器保持 CLOSED**");
    T::eq((int) $router->stats()["fast_open"], 0, "一次都没被短路");
    $svc->setHealthy(true);
}

function caseRecover(): void
{
    echo "== [11] 冷却后探针把它救回来 ==" . PHP_EOL;

    [$s, $router, $svc, $breaker] = bootRouter(false, 3, 300);

    for ($i = 0; $i < 3; $i++) {
        callOnce($s, $router, 500 + $i);
    }
    T::eq($breaker->state(1, Scheduler::nowMs()), CircuitBreaker::OPEN, "先熔断");

    $r = callOnce($s, $router, 510);
    T::eq($r[1], "circuit-open", "冷却中调不通");

    // 节点缓过来了
    $svc->setHealthy(true);
    usleep(350000);   // 等过 300ms 冷却

    $r = callOnce($s, $router, 511);
    T::eq($r[1], "", "探针成功");
    T::eq($r[0], "echo:u511", "拿到真实结果");
    T::eq($breaker->state(1, Scheduler::nowMs()), CircuitBreaker::CLOSED, "熔断器恢复 CLOSED");

    $r = callOnce($s, $router, 512);
    T::eq($r[1], "", "后续调用正常");
}

function caseProbeFailure(): void
{
    echo "== [12] 节点没缓过来：探针失败，冷却翻倍 ==" . PHP_EOL;

    [$s, $router, $svc, $breaker] = bootRouter(false, 3, 300);
    for ($i = 0; $i < 3; $i++) {
        callOnce($s, $router, 600 + $i);
    }
    usleep(350000);

    // 冷却到了，放一个探针 — 但节点还是半死的
    $r = callOnce($s, $router, 610);
    T::eq($r[1], Rpc::ERR_TIMEOUT, "探针也超时");
    T::eq($breaker->state(1, Scheduler::nowMs()), CircuitBreaker::OPEN, "重新熔断");
    T::eq($breaker->cooldown(1), 600, "冷却翻倍 300 → 600ms");

    $r = callOnce($s, $router, 611);
    T::eq($r[1], "circuit-open", "立刻恢复拒绝，不会连续打它");
}

function caseInFlightCap(): void
{
    echo "== [13] 单目标在飞上限（雪崩的真正起点）==" . PHP_EOL;

    // 熔断阈值设 100（本例不会触发），专门测"熔断还没生效之前"能挡住多少。
    // 这不是替代熔断，是补它的空档：前 3 次超时要等 3×120ms 才回来，
    // 这段时间里熔断什么都拦不住，全靠这一层把爆炸半径框住
    [$s, $router, $svc, $breaker] = bootRouter(false, 100, 2000, 4);

    $accepted = 0;
    $saturated = 0;
    $done = 0;
    for ($i = 0; $i < 10; $i++) {
        $sent = $router->call(700, "echo", "x" . $i, function (?string $r, string $e) use (&$done, &$saturated): void {
            $done++;
            if ($e === "target-saturated") {
                $saturated++;
            }
        });
        if ($sent) {
            $accepted++;
        }
    }

    T::eq($accepted, 4, "上限 4：只放 4 个出去");
    T::eq($saturated, 6, "其余 6 个立刻 target-saturated（同步回调，没发）");
    T::eq($router->inFlight(1), 4, "在飞数停在 4，没有超发");

    // 此刻那 4 个还在飞，所以熔断器上应该一条失败都没有——
    // 饱和拒绝压根没发请求，对"对方健不健康"没有信息量，不该记在它头上
    T::eq($breaker->fails(1), 0, "饱和拒绝没有记成对端的失败");
    T::eq($breaker->state(1, Scheduler::nowMs()), CircuitBreaker::CLOSED, "熔断器状态没被污染");

    // 排空：那 4 个会各自走到 120ms 超时
    $spin = 0;
    while ($done < 10 && $spin < 600) {
        $s->tick();
        usleep(1000);
        $spin++;
    }
    T::eq($done, 10, "10 个回调都回来了（没有一个永远挂着）");
    T::eq($router->inFlight(1), 0, "排空后在飞数归零（减在飞数没漏）");
    T::eq((int) $router->stats()["saturated"], 6, "统计里记了 6 次饱和拒绝");
    T::eq($breaker->fails(1), 4, "放出去的 4 个超时，如实记 4 次失败（这部分该记）");
}

function main(): void
{
    caseStates();
    caseProbe();
    caseBackoff();
    caseNoExtend();
    caseRelease();
    caseIsolation();
    caseClassify();
    caseTripFast();
    caseFallback();
    caseBusinessError();
    caseRecover();
    caseProbeFailure();
    caseInFlightCap();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
