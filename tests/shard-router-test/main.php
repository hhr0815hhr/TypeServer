<?php

/**
 * 跨服路由的单进程测试：语义正确性。
 *
 * 真正的"跨进程透明"在 `shard-cross` 里（两个进程，一半请求落本地一半落远端）；
 * 这里先钉死四件事：
 *   1. 落到自己的 uid 走本地投递，结果和直连一致
 *   2. codec 不一致要在**发出去之前**报出来，而不是等对端说"我解不出来"
 *   3. 节点过期 / 环为空要立刻失败，不能挂到超时
 *   4. 环重建后 uid 的归属会跟着变
 */

use TSF\Cluster\Addr;
use TSF\Cluster\Cluster;
use TSF\Discovery\FileRegistry;
use TSF\Discovery\NodeInfo;
use TSF\Rpc\Client;
use TSF\Rpc\Codec\RawCodec;
use TSF\Rpc\Contract\RpcCodec;
use TSF\Rpc\Request;
use TSF\Rpc\Response;
use TSF\Rpc\Router as RpcRouter;
use TSF\Shard\Router;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

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

/** 跨服入口：只提供一个 echo 方法 */
final class EntryService extends Service
{
    // 属性不要和方法同名：AOT 下 `served` 同时是属性和方法时行为没验证过，
    // 第一版这么写导致 handler 的返回值静默变成空串，排查了很久
    public int $servedCount = 0;

    private RpcRouter $rpc;

    public function __construct(?RpcCodec $codec = null)
    {
        $this->rpc = new RpcRouter($codec);
        $this->rpc->on("echo", function (string $b, Message $m): string {
            $this->servedCount++;
            return "echo:" . $b;
        });
    }

    public function onMessage(Message $m): void
    {
        $this->rpc->handle($m);
    }

    public function served(): int
    {
        return $this->servedCount;
    }
}

/**
 * 起一个"我是节点 $nodeId"的调度器。
 *
 * 必须 `setCluster()`：`Scheduler::$localNode` 默认是 0，只有 setCluster 才会
 * 取 cluster 的 nodeId。不设的话 `Addr::pack(1, id)` 算出来的地址节点位是 1，
 * 而调度器认为自己是 0，于是"本地投递"会被判成跨节点、走进 Cluster 分支然后丢掉——
 * 表现是"路由明明指向自己，但服务一次都没被调用"。
 *
 * 这里不 listen（不建 socket 文件），只要那个 nodeId。
 */
function bootScheduler(int $nodeId = 1): Scheduler
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $path = "/tmp/tsf_sr_probe_" . $nodeId . ".sock";
    if (file_exists($path)) {
        unlink($path);
    }
    $c = new Cluster($nodeId, $path);
    $c->listen();   // 必须真的 listen：只 setCluster 不 listen 时 tick 里的 poll 行为
    $s->setCluster($c);
    return $s;
}

function freshDir(string $name): string
{
    $dir = "/tmp/tsf_sr_" . $name;
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

function drain(Scheduler $s, int $times = 6): void
{
    for ($i = 0; $i < $times; $i++) {
        $s->tick();
    }
}

/** 1. 路由到自己：走本地，结果和直连一致 */
function caseLocal(): void
{
    echo "== [1] 本机命中 ==" . PHP_EOL;

    $s = bootScheduler(1);
    $entry = $s->spawnOf(function (): EntryService {
        return new EntryService();
    });
    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(300);
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        T::ok(false, "client 没起来");
        return;
    }

    $dir = freshDir("local");
    $reg = new FileRegistry($dir, 3000);
    // entry 用本地 id（不是完整 addr）：跨服路由会自己 pack 成完整地址
    $reg->register(new NodeInfo(1, "/tmp/tsf_sr_node1.sock", "json", [], Addr::id($entry)));

    $router = new Router($reg, $rpc, 1, 3000, 150, 1000);
    T::eq(count($router->ring()->nodes()), 1, "环里 1 个节点");
    T::eq($router->nodeFor(1001), 1, "uid=1001 路由到自己");

    $got = "";
    $err = "";
    $router->call(1001, "echo", "hello", function (?string $r, string $e) use (&$got, &$err): void {
        $got = $r === null ? "<null>" : $r;
        $err = $e;
    });
    drain($s);

    // 对照：绕过 Router 直连入口，用来分清"是 RPC 层的问题还是 Router 的问题"
    $direct = "";
    $derr = "";
    $rpc->call($entry, "echo", "hello", function (?string $r, string $e) use (&$direct, &$derr): void {
        $direct = $r === null ? "<null>" : $r;
        $derr = $e;
    });
    drain($s);
    T::eq($direct, "echo:hello", "直连入口服务正常（对照组），err=" . $derr);

    T::eq($err, "", "本机调用无错误");
    T::eq($got, "echo:hello", "本机调用结果正确");

    $svc = $s->serviceOf($entry);
    if ($svc instanceof EntryService) {
        // 2 次：1 次来自 Router，1 次来自上面的直连对照
        T::eq($svc->served(), 2, "入口服务被调用 2 次（Router 一次 + 对照一次）");
    }

    $st = $router->stats();
    T::eq((int) $st["local"], 1, "统计记为本地命中");
    T::eq((int) $st["remote"], 0, "没有远端命中");

    // 多个 uid 都归自己（单节点场景）
    $n = 0;
    for ($uid = 1; $uid <= 50; $uid++) {
        if ($router->nodeFor($uid) === 1) {
            $n++;
        }
    }
    T::eq($n, 50, "单节点时所有 uid 都归自己");
}

/** 2. codec 不一致：发之前就报出来 */
function caseCodecMismatch(): void
{
    echo "== [2] codec 不一致 ==" . PHP_EOL;

    $s = bootScheduler(1);
    $entry = $s->spawnOf(function (): EntryService {
        return new EntryService();   // json
    });
    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(300, new RawCodec());   // raw
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        return;
    }

    $dir = freshDir("codec");
    $reg = new FileRegistry($dir, 3000);
    // 注册表里写 json，而本地 client 用 raw
    $reg->register(new NodeInfo(1, "/tmp/tsf_sr_node1.sock", "json", [], Addr::id($entry)));

    $router = new Router($reg, $rpc, 1, 3000, 150, 1000);

    $err = "";
    $t0 = hrtime(true);
    $sent = $router->call(1001, "echo", "x", function (?string $r, string $e) use (&$err): void {
        $err = $e;
    });
    $ms = (hrtime(true) - $t0) / 1e6;

    T::eq($sent, false, "没有发出去");
    T::ok(str_contains($err, "codec-mismatch"), "错误说明是协议不一致", $err);
    T::ok(str_contains($err, "json"), "错误里带上对端协议名", $err);
    T::ok(str_contains($err, "raw"), "错误里带上自己的协议名", $err);
    T::ok($ms < 5, "是同步立刻发现的，没有等待", number_format($ms, 2) . " ms");

    // 关键对比：如果让它发出去会得到什么
    // 对端会回 bad-request（JSON 格式），本地按 raw 解不出来 → bad-response
    // 这条断言是"提前检查值得做"的证据
    $st = $router->stats();
    T::eq((int) $st["codec"], 1, "统计记为 codec 不匹配");
}

/** 3. 空环 / 过期节点 */
function caseFailFast(): void
{
    echo "== [3] 快速失败 ==" . PHP_EOL;

    $s = bootScheduler(1);
    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(300);
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        return;
    }

    // 空注册表
    $dir = freshDir("empty");
    $reg = new FileRegistry($dir, 3000);
    $router = new Router($reg, $rpc, 1, 3000, 150, 1000);

    $err = "";
    $sent = $router->call(1001, "echo", "x", function (?string $r, string $e) use (&$err): void {
        $err = $e;
    });
    T::eq($sent, false, "空环不发");
    T::eq($err, "no-node", "错误是 no-node");
    T::eq($router->nodeFor(1001), 0, "空环时 nodeFor 返回 0");

    // 注册一个，但让它过期（TTL 设很小，sleep 过去）
    $dir2 = freshDir("stale");
    $reg2 = new FileRegistry($dir2, 1000);   // TTL 1s
    $reg2->register(new NodeInfo(1, "/tmp/tsf_sr_node1.sock", "json", [], 1));
    $router2 = new Router($reg2, $rpc, 1, 1000, 150, 100000);   // 刷新间隔很长，避免自动捞回来

    T::eq($router2->nodeFor(1001), 1, "刚开始路由正常");

    // 先睡 1.6 秒（稳稳超过 1 秒 TTL），查一次；没过期就再等——而不是只查一次。
    // 2026-11-27 实测：WSL2 的墙钟会被反复校正（journal 里每 30 秒一条 "Clock change detected"，
    // 还有时间往回走的），而过期判断用的是墙钟——睡的这 1.6 秒里墙钟往回拨超过 0.6 秒，
    // 节点看起来就还活着，这条断言偶发红（全量里红过一次，单独重跑 8 次都不红）。
    // 这里断言的行为是「超过 TTL 之后不再向它发」，所以给一个上限（再等最多 4.5 秒）：
    // 正常机器上第一次就满足；墙钟抖动时多等几轮。
    usleep(1600000);
    $stale = false;
    $err2 = "";
    $sent2 = false;
    $tries = 0;
    do {
        $tries++;
        $err2 = "";
        $sent2 = $router2->call(1001, "echo", "x", function (?string $r, string $e) use (&$err2): void {
            $err2 = $e;
        });
        // 环还没重建（刷新间隔 100s），但节点已经过期 → 必须在发之前拦住
        $stale = $sent2 === false || $err2 === "node-stale";
        if (!$stale) {
            usleep(500000);
        }
    } while (!$stale && $tries < 10);
    T::ok($stale, "过期节点不发",
        "sent=" . ($sent2 ? "1" : "0") . " err=" . $err2 . " 第 " . $tries . " 次才判过期");
}

/** 4. 节点变化后 uid 的归属会跟着变 */
function caseRebalance(): void
{
    echo "== [4] 节点变化 ==" . PHP_EOL;

    $s = bootScheduler(1);
    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(300);
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        return;
    }

    $dir = freshDir("rebal");
    $reg = new FileRegistry($dir, 3000);
    $reg->register(new NodeInfo(1, "/tmp/n1.sock", "json", [], 1));

    $router = new Router($reg, $rpc, 1, 3000, 150, 0);   // 每次都刷新
    T::eq(count($router->ring()->nodes()), 1, "初始 1 个节点");

    // 记录一批 uid 的归属
    $before = [];
    for ($i = 1; $i <= 100; $i++) {
        $before[] = $router->nodeFor($i);
    }

    // 加一个节点
    $reg->register(new NodeInfo(2, "/tmp/n2.sock", "json", [], 1));
    $n = $router->refresh();
    T::eq($n, 2, "refresh 拿到 2 个节点");

    $moved = 0;
    $toTwo = 0;
    for ($i = 1; $i <= 100; $i++) {
        $now = $router->nodeFor($i);
        if ($now !== $before[$i - 1]) {
            $moved++;
            if ($now === 2) {
                $toTwo++;
            }
        }
    }
    T::ok($moved > 0, "有 uid 换了节点", "moved=" . $moved);
    T::eq($moved, $toTwo, "所有变更都流向新节点（一致性哈希的性质）");

    // 删掉节点 2
    $reg->unregister(2);
    $router->refresh();
    T::eq(count($router->ring()->nodes()), 1, "删后回到 1 个节点");
}

/** 5. 同一个 uid 稳定 */
function caseStable(): void
{
    echo "== [5] 稳定性 ==" . PHP_EOL;

    $s = bootScheduler(1);
    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(300);
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        return;
    }

    $dir = freshDir("stable");
    $reg = new FileRegistry($dir, 3000);
    for ($i = 1; $i <= 4; $i++) {
        $reg->register(new NodeInfo($i, "/tmp/n" . $i . ".sock", "json", [], 1));
    }

    $router = new Router($reg, $rpc, 1, 3000, 150, 0);

    $a = $router->nodeFor(12345);
    $b = $router->nodeFor(12345);
    $c = $router->nodeFor(12345);
    T::eq($a, $b, "同一个 uid 连续两次结果相同");
    T::eq($a, $c, "连续三次也相同");

    // 4 个节点时，2000 个 uid 的分布应当大致均衡。
    // 样本取 2000 而不是 1000：1000 个时单节点占比的自然波动能到 40% 上下，
    // 断言会偶发失败——那是统计噪声，不是路由错乱
    $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    for ($uid = 1; $uid <= 2000; $uid++) {
        $n = $router->nodeFor($uid);
        $dist[$n] = ($dist[$n] ?? 0) + 1;
    }
    $max = 0;
    foreach ($dist as $nodeId => $cnt) {
        if ($cnt > $max) {
            $max = $cnt;
        }
    }
    T::ok($max < 700, "4 节点下最大份额 < 35%（2000 样本）", "max=" . $max . "/2000");
}

function main(): void
{
    caseLocal();
    caseCodecMismatch();
    caseFailFast();
    caseRebalance();
    caseStable();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
