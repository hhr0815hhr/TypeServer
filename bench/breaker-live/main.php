<?php

/**
 * 熔断的实机验证：三个进程，其中一个是"半死"的。
 *
 * ```
 *   节点 1  客户端 + 服务（也是路由发起方）
 *   节点 2  健康：心跳正常、请求正常回
 *   节点 3  半死：心跳正常、**收包也正常，但一个都不回**
 * ```
 *
 * 节点 3 模拟的是这些情况：握手之后工作循环卡住、handler 里死循环、
 * 依赖的下游全超时、滚动更新时旧进程还没排空。
 *
 * **关键点：注册表完全看不出节点 3 有问题。** 它的 `.beat` 一直在写，
 * `alive()` 一直把它算在内。所以这个用例测的是"熔断器能不能发现
 * 服务发现发现不了的那一半"——如果这里改用"杀掉节点 3"，TTL 会先把它摘掉，
 * 测出来的就只是 TTL 而不是熔断。
 *
 * 要验证四件事：
 *   1. 熔断发生得够快（验收线：5 秒内不再往它发请求）
 *   2. 熔断后立刻返回，不再等满超时
 *   3. 熔断期间**节点 2 的调用一次都不受影响**（不雪崩）
 *   4. 熔断之后注册表仍然认为节点 3 活着（证明这两层是互补的，不是重复的）
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

const REG_DIR = "/tmp/tsf_bk_reg";
const SOCK1 = "/tmp/tsf_bk1.sock";
const SOCK2 = "/tmp/tsf_bk2.sock";
const SOCK3 = "/tmp/tsf_bk3.sock";
const TTL_MS = 3000;

/** 正常节点 */
final class EchoService extends Service
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
            return "echo:" . $b . "@n" . $this->nodeId;
        });
    }

    public function onMessage(Message $m): void
    {
        $this->rpc->handle($m);
    }
}

/**
 * 半死节点上的服务：**收下消息，什么都不做**。
 *
 * 用"不回"而不是"不接"，是因为不接的话连接都建不起来，
 * 那就退化成"节点不在"了——TTL 会先摘掉它，测不到熔断。
 */
final class BlackholeService extends Service
{
    public int $received = 0;

    public function onMessage(Message $m): void
    {
        $this->received++;
        // 刻意不回复、不处理
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

/** 起一个节点：监听 + 注册 + 心跳 + 入口服务 */
function bootNode(int $nodeId, string $sock, bool $healthy): array
{
    $c = new Cluster($nodeId, $sock);
    $c->listen();
    $s = Scheduler::instance();
    $s->setCluster($c);

    if ($healthy) {
        $entry = $s->spawnOf(function () use ($nodeId): EchoService {
            return new EchoService($nodeId);
        });
    } else {
        $entry = $s->spawnOf(function (): BlackholeService {
            return new BlackholeService();
        });
    }

    $reg = new FileRegistry(REG_DIR, TTL_MS);
    $info = new NodeInfo($nodeId, $sock, "json", ["role" => "game"], Addr::id($entry));
    $s->spawnOf(function () use ($reg, $info): LocalNode {
        return new LocalNode($reg, $info, 1000);
    });

    return [$s, $c, $entry];
}

function runServer(int $nodeId, int $seconds, bool $healthy): void
{
    [$s, $c, $entry] = bootNode($nodeId, $nodeId === 2 ? SOCK2 : SOCK3, $healthy);
    echo "node" . $nodeId . ": listening healthy=" . ($healthy ? "1" : "0") . PHP_EOL;

    $deadline = Scheduler::nowMs() + $seconds * 1000;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(100);
    }

    $svc = $s->serviceOf($entry);
    if ($healthy && $svc instanceof EchoService) {
        echo "node" . $nodeId . ": served=" . $svc->served . PHP_EOL;
    }
    if (!$healthy && $svc instanceof BlackholeService) {
        echo "node" . $nodeId . ": **收下了 " . $svc->received . " 个请求，一个都没回**" . PHP_EOL;
    }
    $cs = $c->stats();
    echo "node" . $nodeId . ": cluster_in=" . $cs["in"] . " cluster_out=" . $cs["out"] . PHP_EOL;
    $c->closeAll();
}

function runClient(int $maxProbe, int $healthyChecks): int
{
    $c = new Cluster(1, SOCK1);
    $c->listen();
    $s = Scheduler::instance();
    $s->setCluster($c);

    $entry = $s->spawnOf(function (): EchoService {
        return new EchoService(1);
    });

    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(120);
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        echo "client: Client 没起来" . PHP_EOL;
        return 1;
    }

    $reg = new FileRegistry(REG_DIR, TTL_MS);
    $info = new NodeInfo(1, SOCK1, "json", ["role" => "game"], Addr::id($entry));
    $s->spawnOf(function () use ($reg, $info): LocalNode {
        return new LocalNode($reg, $info, 1000);
    });

    $c->connect(2, SOCK2);
    $c->connect(3, SOCK3);

    // 等三个节点都进环
    $nodes = 0;
    for ($i = 0; $i < 300; $i++) {
        $s->tick();
        usleep(10000);
        $alive = $reg->alive();
        $nodes = count($alive);
        if ($nodes >= 3) {
            break;
        }
    }
    echo "client: 注册表里活着 " . $nodes . " 个节点" . PHP_EOL;
    if ($nodes < 3) {
        echo "client: 节点不足 3 个，验证无意义" . PHP_EOL;
        $c->closeAll();
        return 1;
    }

    $breaker = new CircuitBreaker(3, 2000, 30000);
    $router = new Router($reg, $rpc, 1, TTL_MS, 150, 500, $breaker);

    // 先确认节点 3 真的在环里、真的被注册表认为活着
    $sickIsAlive = false;
    foreach ($reg->alive() as $n) {
        if ($n->nodeId === 3) {
            $sickIsAlive = true;
        }
    }
    echo "client: 节点 3 在注册表里 存活=" . ($sickIsAlive ? "是" : "否") . "（它心跳正常）" . PHP_EOL;

    // ── 1. 打节点 3，等熔断 ──
    $firstFailAt = 0;
    $tripAt = 0;
    $timeouts = 0;
    $probes = 0;
    $t0 = Scheduler::nowMs();

    for ($uid = 1; $uid <= 60000 && $probes < $maxProbe; $uid++) {
        if ($router->nodeFor($uid) !== 3) {
            continue;
        }
        $probes++;
        $err = "";
        $done = 0;
        $router->call($uid, "echo", "u" . $uid, function (?string $r, string $e) use (&$err, &$done): void {
            $err = $e;
            $done++;
        });
        $spin = 0;
        while ($done === 0 && $spin < 400) {
            $s->tick();
            usleep(500);
            $spin++;
        }

        if ($err === "timeout") {
            if ($firstFailAt === 0) {
                $firstFailAt = Scheduler::nowMs();
                echo "client: 第一次超时（耗时 " . (Scheduler::nowMs() - $t0) . "ms 起）" . PHP_EOL;
            }
            $timeouts++;
        } elseif ($err === "circuit-open") {
            $tripAt = Scheduler::nowMs();
            break;
        }
    }

    if ($tripAt === 0 || $firstFailAt === 0) {
        echo "FAIL 没有观察到熔断（超时 " . $timeouts . " 次，探测 " . $probes . " 次）" . PHP_EOL;
        $c->closeAll();
        return 1;
    }

    $tripDelay = $tripAt - $firstFailAt;
    printf("client: 连续 %d 次超时后熔断，从第一次失败到不再发 = %dms\n", $timeouts, $tripDelay);

    // ── 2. 熔断后：立刻返回，不等超时 ──
    $sickUid = 0;
    for ($u = 1; $u <= 60000; $u++) {
        if ($router->nodeFor($u) === 3) {
            $sickUid = $u;
            break;
        }
    }
    $fastUs = [];
    $fastOpen = 0;
    for ($i = 0; $i < 10; $i++) {
        $t = hrtime(true);
        $err = "";
        $router->call($sickUid, "echo", "x", function (?string $r, string $e) use (&$err): void {
            $err = $e;
        });
        $us = (hrtime(true) - $t) / 1000.0;
        if ($err === "circuit-open") {
            $fastOpen++;
            $fastUs[] = $us;
        }
    }
    sort($fastUs);
    printf(
        "client: 熔断后 %d/10 次立刻返回 circuit-open，p99=%.1fµs\n",
        $fastOpen,
        pct($fastUs, 0.99)
    );

    // ── 3. 熔断期间，健康的节点 2 完全不受影响 ──
    $ok = 0;
    $bad = 0;
    $lat = [];
    $checked = 0;
    for ($uid = 1; $uid <= 60000 && $checked < $healthyChecks; $uid++) {
        if ($router->nodeFor($uid) !== 2) {
            continue;
        }
        $checked++;
        $t = hrtime(true);
        // 用 "pending" 而不是空串当哨兵：成功时 err 也是空串，混在一起就分不清
        $err = "pending";
        $router->call($uid, "echo", "u" . $uid, function (?string $r, string $e) use (&$err, &$ok, &$bad): void {
            $err = $e;
            if ($e === "") {
                $ok++;
            } else {
                $bad++;
            }
        });
        $spin = 0;
        while ($err === "pending" && $spin < 400) {
            $s->tick();
            usleep(200);
            $spin++;
        }
        $lat[] = (hrtime(true) - $t) / 1000.0;
    }
    sort($lat);
    printf(
        "client: 同时打健康节点 2：%d/%d 成功，p50=%.0fµs p99=%.0fµs\n",
        $ok,
        $checked,
        pct($lat, 0.5),
        pct($lat, 0.99)
    );

    // ── 4. 熔断之后，注册表是不是还认为节点 3 活着 ──
    $stillAlive = false;
    foreach ($reg->alive() as $n) {
        if ($n->nodeId === 3) {
            $stillAlive = true;
        }
    }

    $st = $router->stats();
    $bs = $st["breaker"];
    printf(
        "client: 路由统计 local=%d remote=%d fast_open=%d | 熔断 trips=%d probes=%d short_circuited=%d\n",
        (int) $st["local"],
        (int) $st["remote"],
        (int) $st["fast_open"],
        (int) $bs["trips"],
        (int) $bs["probes"],
        (int) $bs["short_circuited"]
    );
    echo "client: 熔断后注册表认为节点 3 存活=" . ($stillAlive ? "是" : "否") . PHP_EOL;

    $passA = $tripDelay <= 5000;
    $passB = $fastOpen === 10;
    $passC = $ok === $checked && $bad === 0;
    $passD = $stillAlive;

    echo $passA ? "PASS 5 秒内摘除（实际 " . $tripDelay . "ms）" : "FAIL 摘除太慢：" . $tripDelay . "ms" . PHP_EOL;
    echo $passB ? "PASS 熔断后不再等超时" : "FAIL 熔断后仍有请求被放出去（" . (10 - $fastOpen) . " 次）" . PHP_EOL;
    echo $passC ? "PASS 健康节点不受影响（无雪崩）" : "FAIL 健康节点被牵连：失败 " . $bad . " 次" . PHP_EOL;
    echo $passD ? "PASS 注册表确实看不出节点 3 有问题（两层是互补的）" : "FAIL 注册表已经把节点 3 摘了，这个用例没测到熔断" . PHP_EOL;

    $c->closeAll();
    return ($passA && $passB && $passC && $passD) ? 0 : 1;
}

function main(): void
{
    $args = $_SERVER["argv"] ?? [];
    $mode = isset($args[1]) ? (string) $args[1] : "help";
    $a = isset($args[2]) ? (int) $args[2] : 0;
    $b = isset($args[3]) ? (int) $args[3] : 0;

    Scheduler::reset();

    if ($mode === "healthy") {
        runServer($a > 0 ? $a : 2, $b > 0 ? $b : 30, true);
        return;
    }
    if ($mode === "sick") {
        runServer($a > 0 ? $a : 3, $b > 0 ? $b : 30, false);
        return;
    }
    if ($mode === "client") {
        exit(runClient($a > 0 ? $a : 30, $b > 0 ? $b : 20));
    }
    echo "用法: breaker_live healthy <id> <sec> | sick <id> <sec> | client <maxProbe> <healthyChecks>" . PHP_EOL;
}
