<?php

/**
 * 跨服调用透明：两个进程，一半 uid 落本地、一半落远端，调用代码完全一样。
 *
 * 之所以要两个进程：单进程只能验证"路由到自己"，验证不了"真的过了一次网络
 * 还拿到同样的结果"。这里有三个东西必须同时成立：
 *
 *   1. uid 的归属由一致性哈希决定（不是"先试本地，不行再远端"）
 *   2. 落本地的走本地投递，落差地的走 UDS，**调用方看到的形式一致**
 *   3. 回来的结果里带着"是哪个节点处理的"，能和路由结论对上
 */

use TSF\Cluster\Addr;
use TSF\Cluster\Cluster;
use TSF\Discovery\FileRegistry;
use TSF\Discovery\LocalNode;
use TSF\Discovery\NodeInfo;
use TSF\Rpc\Client;
use TSF\Rpc\Router as RpcRouter;
use TSF\Shard\Router;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

const REG_DIR = "/tmp/tsf_cross_reg";
const SOCK1 = "/tmp/tsf_cross1.sock";
const SOCK2 = "/tmp/tsf_cross2.sock";
const TTL_MS = 5000;

/** 入口服务：返回值里带上自己所在的节点号，用来验证请求真的到了预期节点 */
final class EchoService extends Service
{
    private RpcRouter $rpc;

    private int $nodeId;

    public int $servedCount = 0;

    public function __construct(int $nodeId)
    {
        $this->nodeId = $nodeId;
        $this->rpc = new RpcRouter();
        $this->rpc->on("echo", function (string $b, Message $m): string {
            $this->servedCount++;
            return "echo:" . $b . "@n" . $this->nodeId;
        });
    }

    public function onMessage(Message $m): void
    {
        $this->rpc->handle($m);
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

/** 节点 1：只提供入口服务 + 心跳，等着被调用 */
function runServer(int $seconds): void
{
    $c = new Cluster(1, SOCK1);
    $c->listen();
    $s = Scheduler::instance();
    $s->setCluster($c);

    $entry = $s->spawnOf(function (): EchoService {
        return new EchoService(1);
    });

    $reg = new FileRegistry(REG_DIR, TTL_MS);
    $info = new NodeInfo(1, SOCK1, "json", ["role" => "game"], Addr::id($entry));
    $s->spawnOf(function () use ($reg, $info): LocalNode {
        return new LocalNode($reg, $info, 1000);
    });

    echo "server: node=1 entry=" . $entry . " listening" . PHP_EOL;

    $deadline = Scheduler::nowMs() + $seconds * 1000;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        // 这个间隔直接决定跨服延迟：Cluster 的收包在 tick 里做，
        // 对端每 3ms 才 poll 一次，这边看到的往返就是 3ms 起
        usleep(100);
    }

    $svc = $s->serviceOf($entry);
    $served = $svc instanceof EchoService ? $svc->servedCount : 0;
    $st = $s->stats();
    $cs = $c->stats();
    echo "server: served=" . $served
        . " cluster_out=" . $cs["out"] . " cluster_in=" . $cs["in"]
        . " undelivered=" . $st["undelivered"] . PHP_EOL;
    $c->closeAll();
}

/** 节点 2：既是服务节点，也是调用方 */
function runClient(int $count): int
{
    $c = new Cluster(2, SOCK2);
    if (!$c->connect(1, SOCK1)) {
        echo "client: 连接节点 1 失败" . PHP_EOL;
        return 1;
    }
    $s = Scheduler::instance();
    $s->setCluster($c);

    $entry = $s->spawnOf(function (): EchoService {
        return new EchoService(2);
    });
    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(800);
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        echo "client: RPC Client 没起来" . PHP_EOL;
        return 1;
    }

    $reg = new FileRegistry(REG_DIR, TTL_MS);
    $info = new NodeInfo(2, SOCK2, "json", ["role" => "game"], Addr::id($entry));
    $s->spawnOf(function () use ($reg, $info): LocalNode {
        return new LocalNode($reg, $info, 1000);
    });

    // 让心跳先跑一轮，确保两个节点都进了环
    for ($i = 0; $i < 5; $i++) {
        $s->tick();
        usleep(10000);
    }

    $router = new Router($reg, $rpc, 2, TTL_MS, 150, 1000);
    $nodesInRing = count($router->ring()->nodes());
    echo "client: 环里有 " . $nodesInRing . " 个节点" . PHP_EOL;
    if ($nodesInRing < 2) {
        echo "client: 只看到 " . $nodesInRing . " 个节点，跨服验证无意义" . PHP_EOL;
        $c->closeAll();
        return 1;
    }

    $ok = 0;
    $err = 0;
    $wrongNode = 0;
    $latLocal = [];
    $latRemote = [];
    $done = 0;

    for ($uid = 1; $uid <= $count; $uid++) {
        // 先问路由"这个 uid 该去哪个节点"，一会儿用结果校验返回值
        $expectNode = $router->nodeFor($uid);
        $t0 = hrtime(true);
        $router->call($uid, "echo", "u" . $uid, function (?string $r, string $e) use (
            &$ok,
            &$err,
            &$wrongNode,
            &$latLocal,
            &$latRemote,
            &$done,
            $uid,
            $expectNode,
            $t0
        ): void {
            if ($e !== "" || $r === null) {
                $err++;
                return;
            }
            $ok++;
            $us = (hrtime(true) - $t0) / 1000.0;
            // 返回体形如 echo:u42@n1，看它是不是由预期的节点处理
            $want = "@n" . $expectNode;
            if (!str_contains($r, $want)) {
                $wrongNode++;
            }
            if ($expectNode === 2) {
                $latLocal[] = $us;
            } else {
                $latRemote[] = $us;
            }
            $done++;
        });

        // 推动到收到响应。**不 sleep**：`tick()` 自己会 flush Cluster 的攒批，
        // 人为 sleep 只是把测试框架的轮询间隔算进延迟里，测出来的数字没有意义
        $spin = 0;
        while ($done < $uid && $spin < 500) {
            $s->tick();
            $spin++;
        }
    }

    sort($latLocal);
    sort($latRemote);

    $st = $router->stats();
    printf("client: total=%d ok=%d err=%d 节点不符=%d\n", $count, $ok, $err, $wrongNode);
    printf(
        "client: 路由统计 local=%d remote=%d no_node=%d stale=%d codec=%d\n",
        (int) $st["local"],
        (int) $st["remote"],
        (int) $st["no_node"],
        (int) $st["stale"],
        (int) $st["codec"]
    );
    if ($latLocal !== []) {
        printf(
            "client: 本地  n=%d p50=%.0fµs p99=%.0fµs\n",
            count($latLocal),
            pct($latLocal, 0.5),
            pct($latLocal, 0.99)
        );
    }
    if ($latRemote !== []) {
        printf(
            "client: 远端  n=%d p50=%.0fµs p99=%.0fµs\n",
            count($latRemote),
            pct($latRemote, 0.5),
            pct($latRemote, 0.99)
        );
    }

    $passA = $ok === $count && $err === 0;
    $passB = $wrongNode === 0;
    $passC = count($latLocal) > 0 && count($latRemote) > 0;
    $passD = (int) $st["local"] + (int) $st["remote"] === $count;

    echo $passA ? "PASS 全部调用成功" : "FAIL 有失败调用" . PHP_EOL;
    echo $passB ? "PASS 结果来自预期节点（路由透明）" : "FAIL 有结果来自非预期节点" . PHP_EOL;
    echo $passC ? "PASS 本地与远端两条路径都被走到" : "FAIL 只走了一条路径" . PHP_EOL;
    echo $passD ? "PASS 路由统计与实际调用数一致" : "FAIL 统计对不上" . PHP_EOL;

    $c->closeAll();
    return ($passA && $passB && $passC && $passD) ? 0 : 1;
}

function main(): void
{
    $args = $_SERVER["argv"] ?? [];
    $mode = isset($args[1]) ? (string) $args[1] : "help";
    $a = isset($args[2]) ? (int) $args[2] : 0;

    if ($mode === "server") {
        runServer($a > 0 ? $a : 40);
        return;
    }
    if ($mode === "client") {
        exit(runClient($a > 0 ? $a : 200));
    }
    echo "用法: shard_cross server|client [n]" . PHP_EOL;
}
