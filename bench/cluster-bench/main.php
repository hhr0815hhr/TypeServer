<?php

/**
 * 集群压测：3 个节点，跨服调用混合，**流量不停的情况下把节点 3 冻住**。
 *
 * 前面四项（RPC / 发现 / 分片 / 熔断）都是分开测的，各自的数字都好看。
 * 这一项要回答的是别的问题：**同时工作时还成立吗**。
 *
 * ## 三阶段
 *
 * ```
 *   基线    3 个节点全健康，跑满一轮          → 这是 p99 的下限
 *   冻结    节点 3 收包但不回（心跳照跳）      → 健康节点的 p99 会不会被拖坏
 *   恢复    解冻，等冷却，再打               → 熔断器能不能自己走出来
 * ```
 *
 * ## 为什么用"冻结"而不是"杀掉"
 *
 * 杀掉节点的话，注册表 3 秒把它摘掉，环重建成 2 节点，uid 全部重分布——
 * 测出来的是"缩容"，不是"故障"。而真实故障里最难受的恰恰是
 * **节点还在、但它不干活**：包发得出去、回不来，请求一直挂到超时。
 *
 * ## "无超时雪崩"到底看什么
 *
 * 不是看总成功率（节点 3 的请求注定失败），是看**健康节点有没有被牵连**：
 *
 * - 健康节点的 p99 有没有超过 5 ms
 * - 健康节点的成功率还是不是 100%
 * - 整个压测的吞吐有没有塌下去起不来
 *
 * 雪崩的机理是"坏节点的请求占满了待发表和事件循环，把好请求也拖到超时"。
 * 熔断器要做的就是把这个链条在第三次超时之后切断。
 */

use TSF\Breaker\CircuitBreaker;
use TSF\Cluster\Addr;
use TSF\Cluster\Cluster;
use TSF\Discovery\FileRegistry;
use TSF\Discovery\LocalNode;
use TSF\Discovery\NodeInfo;
use TSF\Rpc\Client;
use TSF\Rpc\Router as RpcRouter;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;
use TSF\Shard\Router;

const REG_DIR = "/tmp/tsf_cb_reg";
const SOCK1 = "/tmp/tsf_cb1.sock";
const SOCK2 = "/tmp/tsf_cb2.sock";
const SOCK3 = "/tmp/tsf_cb3.sock";

/** 有这个文件就"冻住"：收包但一个都不回 */
const FREEZE = "/tmp/tsf_cb_freeze";

const TTL_MS = 3000;

/** 压测用的 uid 空间（要足够大，三个节点的桶才都填得满） */
const UID_SPACE = 60000;

final class BenchService extends Service
{
    private RpcRouter $rpc;

    private int $nodeId;

    public int $served = 0;

    public function __construct(int $nodeId)
    {
        $this->nodeId = $nodeId;
        $this->rpc = new RpcRouter();
        $this->rpc->on("echo", function (string $b, Message $m): string {
            $this->served++;
            return "ok:" . $b . "@n" . $this->nodeId;
        });
    }

    public function onMessage(Message $m): void
    {
        // 冻结点在**分发之前**：handler 里抛异常会回一个业务错误，
        // 而"业务错误"在熔断器看来是"对端活着"。要模拟的是彻底不回话
        if (is_file(FREEZE) && $this->nodeId === 3) {
            return;
        }
        $this->rpc->handle($m);
    }
}

/** 一批调用的统计。做成类是为了少写一堆 `use (&$a, &$b, &$c)` */
final class Stats
{
    public string $phase = "";

    public int $total = 0;

    public int $startMs = 0;

    public int $endMs = 0;

    /** @var array<int, int> 节点 => 成功数 */
    public array $ok = [];

    /** @var array<int, int> 节点 => 失败数 */
    public array $err = [];

    /** @var array<int, int> 节点 => 超时数 */
    public array $timeout = [];

    /** @var array<int, int> 节点 => 被熔断短路数 */
    public array $open = [];

    /** @var array<int, int> 节点 => 被在飞上限拒掉数 */
    public array $sat = [];

    /** @var array<int, array<int, float>> 节点 => 延迟（µs） */
    public array $lat = [];

    /** 第一次熔断短路发生在相位开始后多少毫秒（-1 表示没发生） */
    public int $firstOpenAt = -1;

    public function record(int $node, float $us, string $err, int $nowMs): void
    {
        $this->total++;
        if ($err === "") {
            $this->ok[$node] = ($this->ok[$node] ?? 0) + 1;
            $this->lat[$node][] = $us;
            return;
        }
        $this->err[$node] = ($this->err[$node] ?? 0) + 1;
        if ($err === "timeout") {
            $this->timeout[$node] = ($this->timeout[$node] ?? 0) + 1;
        } elseif ($err === "circuit-open") {
            $this->open[$node] = ($this->open[$node] ?? 0) + 1;
            if ($this->firstOpenAt < 0) {
                $this->firstOpenAt = $nowMs - $this->startMs;
            }
        } elseif ($err === "target-saturated") {
            $this->sat[$node] = ($this->sat[$node] ?? 0) + 1;
        }
    }

    public function okCount(int $node): int
    {
        return $this->ok[$node] ?? 0;
    }

    public function errCount(int $node): int
    {
        return $this->err[$node] ?? 0;
    }

    public function timeouts(int $node): int
    {
        return $this->timeout[$node] ?? 0;
    }

    public function opens(int $node): int
    {
        return $this->open[$node] ?? 0;
    }

    public function satCount(int $node): int
    {
        return $this->sat[$node] ?? 0;
    }

    public function sortedLat(int $node): array
    {
        $a = $this->lat[$node] ?? [];
        sort($a);
        return $a;
    }

    public function durationMs(): int
    {
        $d = $this->endMs - $this->startMs;
        return $d > 0 ? $d : 1;
    }

    public function rps(): float
    {
        return (float) $this->total / ((float) $this->durationMs() / 1000.0);
    }
}

function pct(array $sorted, float $p): float
{
    $n = count($sorted);
    if ($n === 0) {
        return 0.0;
    }
    $i = (int) ceil($p * $n) - 1;
    if ($i < 0) {
        $i = 0;
    }
    if ($i >= $n) {
        $i = $n - 1;
    }
    return (float) $sorted[$i];
}

/** 一个节点的常规启动：监听 + 入口服务 + 注册 + 心跳 */
function bootNode(int $nodeId, string $sock): array
{
    $c = new Cluster($nodeId, $sock);
    $c->listen();
    $s = Scheduler::instance();
    $s->setCluster($c);

    $entry = $s->spawnOf(function () use ($nodeId): BenchService {
        return new BenchService($nodeId);
    });

    $reg = new FileRegistry(REG_DIR, TTL_MS);
    $info = new NodeInfo($nodeId, $sock, "json", ["role" => "bench"], Addr::id($entry));
    $s->spawnOf(function () use ($reg, $info): LocalNode {
        return new LocalNode($reg, $info, 1000);
    });

    return [$s, $c, $entry, $reg];
}

/** 被动节点：只提供服务，等压测端来打 */
function runNode(int $nodeId, int $seconds): void
{
    $sock = $nodeId === 2 ? SOCK2 : SOCK3;
    [$s, $c, $entry] = bootNode($nodeId, $sock);
    echo "node" . $nodeId . ": listening" . PHP_EOL;

    $deadline = Scheduler::nowMs() + $seconds * 1000;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(50);
    }

    $svc = $s->serviceOf($entry);
    $cs = $c->stats();
    if ($svc instanceof BenchService) {
        echo "node" . $nodeId . ": served=" . $svc->served . PHP_EOL;
    }
    echo "node" . $nodeId . ": cluster_in=" . $cs["in"] . " out=" . $cs["out"] . PHP_EOL;
    $c->closeAll();
}

/**
 * 跑一轮：保持 `$window` 个请求在飞，从 `$seq` 里按序取 [uid, node]。
 *
 * **要有在飞窗口**：一次只发一个的话，测出来的是"串行往返"，
 * 看不到排队——而雪崩恰恰是排队引起的。
 *
 * `$stopOnFirstOpen`：观察到第一次熔断短路就停止发新请求（在飞的照常排空）。
 * 冻结阶段必须用它把**过渡期**和**稳态**分开——混在一起算 p99，
 * 60 个超时的样本会把 2000 个正常样本的数字带偏，问题被平均掉看不出。
 */
function runPhase(
    Scheduler $s,
    Router $router,
    array $seq,
    int $count,
    int $window,
    string $name,
    bool $stopOnFirstOpen = false
): Stats {
    $st = new Stats();
    $st->phase = $name;
    $st->startMs = Scheduler::nowMs();

    $n = count($seq);
    if ($n === 0) {
        $st->endMs = $st->startMs + 1;
        return $st;
    }

    $issued = 0;
    $pending = 0;
    $stop = false;

    while ($issued < $count && !$stop) {
        while ($pending < $window && $issued < $count) {
            $slot = $seq[$issued % $n];
            $uid = (int) $slot[0];
            $node = (int) $slot[1];
            $issued++;
            $pending++;
            $t0 = hrtime(true);
            $router->call($uid, "echo", "u" . $uid, function (?string $r, string $e) use (
                &$pending,
                $st,
                $node,
                $t0
            ): void {
                $pending--;
                $us = (hrtime(true) - $t0) / 1000.0;
                $st->record($node, $us, $e, Scheduler::nowMs());
            });

            // **必须周期性 tick**：被"在飞上限"或"熔断"拒掉时回调是同步的，
            // `$pending` 立刻退回原值，内层循环于是永远满足 `$pending < $window`。
            // 不在这里让出去的话，几千个请求会在一个循环里发完、一个 tick 都不跑，
            // 已经在飞的那些永远收不到响应——压测自己把自己饿死
            if (($issued & 15) === 0) {
                $s->tick();
                // 停止条件也必须在内层查：一旦熔断生效，后面的调用全是同步失败，
                // 内层会一路发到底，外层的检查根本没机会跑
                if ($stopOnFirstOpen && $st->firstOpenAt >= 0) {
                    $stop = true;
                    break;
                }
            }
        }
        $s->tick();
        usleep(30);
        if ($stopOnFirstOpen && $st->firstOpenAt >= 0) {
            $stop = true;
        }
    }

    // 排空：给足够多轮，别让卡住的请求把进程拖死
    $guard = 0;
    while ($pending > 0 && $guard < 40000) {
        $s->tick();
        usleep(30);
        $guard++;
    }

    $st->endMs = Scheduler::nowMs();
    return $st;
}

function printPhase(Stats $st): void
{
    printf(
        "  %-8s total=%d 耗时=%dms 吞吐=%.0f req/s\n",
        $st->phase,
        $st->total,
        $st->durationMs(),
        $st->rps()
    );
    foreach ([1, 2, 3] as $node) {
        $lat = $st->sortedLat($node);
        printf(
            "    n%d  成功=%-5d 失败=%-4d (超时 %-3d / 熔断 %-3d / 饱和 %-3d)  p50=%.0fµs p99=%.0fµs p999=%.0fµs\n",
            $node,
            $st->okCount($node),
            $st->errCount($node),
            $st->timeouts($node),
            $st->opens($node),
            $st->satCount($node),
            pct($lat, 0.5),
            pct($lat, 0.99),
            pct($lat, 0.999)
        );
    }
    if ($st->firstOpenAt > 0) {
        echo "    本相位第 " . $st->firstOpenAt . "ms 开始短路节点 3" . PHP_EOL;
    }
}

function runDrive(int $perPhase, int $window, int $cap): int
{
    $s = Scheduler::instance();
    [$s, $c, $entry, $reg] = bootNode(1, SOCK1);

    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(120);
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        echo "driver: Client 没起来" . PHP_EOL;
        return 1;
    }

    $c->connect(2, SOCK2);
    $c->connect(3, SOCK3);

    $alive = 0;
    for ($i = 0; $i < 400; $i++) {
        $s->tick();
        usleep(10000);
        $alive = count($reg->alive());
        if ($alive >= 3) {
            break;
        }
    }
    echo "driver: 注册表里活着 " . $alive . " 个节点" . PHP_EOL;
    if ($alive < 3) {
        echo "driver: 节点不足 3 个，压测无意义" . PHP_EOL;
        $c->closeAll();
        return 1;
    }

    $breaker = new CircuitBreaker(3, 1200, 30000);
    $router = new Router($reg, $rpc, 1, TTL_MS, 150, 500, $breaker, $cap);
    echo "driver: 单目标在飞上限=" . $cap . PHP_EOL;

    // 按 uid 归属分桶：压测要保证三个节点都被均匀打到
    $buckets = [1 => [], 2 => [], 3 => []];
    for ($uid = 1; $uid <= UID_SPACE; $uid++) {
        $owner = $router->nodeFor($uid);
        if ($owner >= 1 && $owner <= 3) {
            $buckets[$owner][] = $uid;
        }
    }
    // 交错成一条序列：相邻三个请求分别去 1/2/3，"混合"才是真的混合
    $seq = [];
    $maxLen = 0;
    foreach ([1, 2, 3] as $bn) {
        if (count($buckets[$bn]) > $maxLen) {
            $maxLen = count($buckets[$bn]);
        }
    }
    for ($i = 0; $i < $maxLen; $i++) {
        foreach ([1, 2, 3] as $bn) {
            if ($i < count($buckets[$bn])) {
                $seq[] = [$buckets[$bn][$i], $bn];
            }
        }
    }
    printf(
        "driver: uid 分桶 n1=%d n2=%d n3=%d，交错序列 %d 个\n",
        count($buckets[1]),
        count($buckets[2]),
        count($buckets[3]),
        count($seq)
    );

    // 预热：把连接、codec、环都跑热，别把首次连接的代价算进基线
    runPhase($s, $router, $seq, 400, $window, "warmup");

    echo PHP_EOL . "=== 阶段 1：基线（3 节点全健康）===" . PHP_EOL;
    $base = runPhase($s, $router, $seq, $perPhase, $window, "基线");
    printPhase($base);

    echo PHP_EOL . "=== 阶段 2a：冻结节点 3 —— 过渡期（到熔断生效为止）===" . PHP_EOL;
    file_put_contents(FREEZE, "1");
    $trans = runPhase($s, $router, $seq, 3000, $window, "过渡", true);
    printPhase($trans);

    echo PHP_EOL . "=== 阶段 2b：冻结节点 3 —— 稳态（熔断已生效，纯压）===" . PHP_EOL;
    $frozen = runPhase($s, $router, $seq, $perPhase, $window, "稳态", false);
    printPhase($frozen);

    echo PHP_EOL . "=== 阶段 3：解冻，等冷却后恢复 ===" . PHP_EOL;
    if (is_file(FREEZE)) {
        unlink(FREEZE);
    }
    $wait = Scheduler::nowMs() + 1400;
    while (Scheduler::nowMs() < $wait) {
        $s->tick();
        usleep(1000);
    }
    $recover = runPhase($s, $router, $seq, (int) ($perPhase / 3), $window, "恢复");
    printPhase($recover);

    // ── 判定 ──
    $baseAll = $base->sortedLat(1) + $base->sortedLat(2) + $base->sortedLat(3);
    sort($baseAll);
    $baseP99 = pct($baseAll, 0.99);

    // **按节点分开看**：合并成一个数组会被延迟最低的那个节点摊平，
    // 冻结期间真正被牵连的节点（远端两个）反而看不出来
    $frozenN1 = pct($frozen->sortedLat(1), 0.99);
    $frozenN2 = pct($frozen->sortedLat(2), 0.99);
    $transN1 = pct($trans->sortedLat(1), 0.99);
    $transN2 = pct($trans->sortedLat(2), 0.99);

    $healthyErr = $frozen->errCount(1) + $frozen->errCount(2);
    $healthyCnt = $frozen->okCount(1) + $frozen->okCount(2) + $healthyErr;
    // 健康节点的失败要分性质：
    //   timeout / circuit-open  → **被坏节点牵连**（这才是雪崩的样子，必须为 0）
    //   target-saturated       → 自己把并发压到上限了（限流器在干活，不是故障）
    $healthyDragged = $frozen->timeouts(1) + $frozen->timeouts(2)
        + $frozen->opens(1) + $frozen->opens(2);
    $healthySat = $frozen->satCount(1) + $frozen->satCount(2);

    $recLat = $recover->sortedLat(3);
    $recOk = $recover->okCount(3);

    echo PHP_EOL;
    // 基线判据要排除 `target-saturated`：上限配得紧时会零星拒掉自己（本例约 1%），
    // 那是**限流器在正常工作**，不是有节点坏了。把它算成失败的话，
    // 要么测试永远红、要么逼着把上限调大（等于取消隔离）
    $baseErrAll = $base->errCount(1) + $base->errCount(2) + $base->errCount(3);
    $baseSat = $base->satCount(1) + $base->satCount(2) + $base->satCount(3);
    $baseBad = $baseErrAll - $baseSat;
    $passA = $baseP99 <= 5000.0 && $baseBad === 0;
    $passB = $frozenN1 <= 5000.0 && $frozenN2 <= 5000.0;
    $passC = $healthyDragged === 0 && $healthyCnt > 0;
    $passD = $recOk > 0 && pct($recLat, 0.99) <= 5000.0;

    printf(
        "%s 基线 p99=%.0fµs（验收 ≤5000µs，%.0f req/s，饱和拒绝 %d，其它错误 %d）\n",
        $passA ? "PASS" : "FAIL",
        $baseP99,
        $base->rps(),
        $baseSat,
        $baseBad
    );
    printf(
        "%s 冻结稳态：健康节点 p99 n1=%.0fµs n2=%.0fµs（验收 ≤5000µs）\n",
        $passB ? "PASS" : "FAIL",
        $frozenN1,
        $frozenN2
    );
    printf(
        "%s 冻结稳态：健康节点没有被牵连（超时+熔断 %d 次），成功 %d/%d，饱和拒绝 %d，%.0f req/s\n",
        $passC ? "PASS" : "FAIL",
        $healthyDragged,
        $frozen->okCount(1) + $frozen->okCount(2),
        $healthyCnt,
        $healthySat,
        $frozen->rps()
    );
    printf(
        "%s 解冻后节点 3 恢复（成功 %d 次，p99=%.0fµs）\n",
        $passD ? "PASS" : "FAIL",
        $recOk,
        pct($recLat, 0.99)
    );
    printf(
        "     过渡期 %dms：n1 p99=%.0fµs n2 p99=%.0fµs，n3 超时 %d 次（这段代价是躲不掉的）\n",
        $trans->durationMs(),
        $transN1,
        $transN2,
        $trans->timeouts(3)
    );

    $bs = $breaker->stats();
    printf(
        "driver: 熔断 trips=%d probes=%d short_circuited=%d\n",
        (int) $bs["trips"],
        (int) $bs["probes"],
        (int) $bs["short_circuited"]
    );

    $c->closeAll();
    return ($passA && $passB && $passC && $passD) ? 0 : 1;
}

function main(): void
{
    $args = $_SERVER["argv"] ?? [];
    $mode = isset($args[1]) ? (string) $args[1] : "help";
    $a = isset($args[2]) ? (int) $args[2] : 0;
    $b = isset($args[3]) ? (int) $args[3] : 0;
    $c = isset($args[4]) ? (int) $args[4] : 0;

    Scheduler::reset();

    if ($mode === "node") {
        runNode($a > 0 ? $a : 2, $b > 0 ? $b : 40);
        return;
    }
    if ($mode === "drive") {
        exit(runDrive($a > 0 ? $a : 6000, $b > 0 ? $b : 32, $c > 0 ? $c : 64));
    }
    echo "用法: cluster_bench node <id> <sec> | drive <perPhase> <window> <cap>" . PHP_EOL;
}
