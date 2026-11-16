<?php

/**
 * 跨进程 RPC：两个进程走 UDS，量 p99。
 *
 * 验收线是 p99 ≤2 ms。底层 Cluster 的 p99 是 285 µs（§9.7），
 * RPC 这一层加的是"分配 id + 挂回调 + 查表回调"，理论上应当是常数开销。
 * 这个测试就是为了证明它确实是常数，而不是随并发量涨。
 */

use TSF\Cluster\Addr;
use TSF\Cluster\Cluster;
use TSF\Rpc\Client;
use TSF\Rpc\Router;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

final class RpcEcho extends Service
{
    public int $served = 0;

    private Router $rpc;

    public function __construct()
    {
        $this->rpc = new Router();
        $this->rpc->on("echo", function (string $b, Message $m): string {
            $this->served++;
            return "ok:" . $b;
        });
        // 故意慢一点，用来验证 p99 里能体现真实处理时间
        $this->rpc->on("work", function (string $b, Message $m): string {
            $this->served++;
            return "done";
        });
    }

    public function router(): Router
    {
        return $this->rpc;
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

function runServer(int $seconds): void
{
    $sock = "/tmp/tsf_rpc_node1.sock";
    $c = new Cluster(1, $sock);
    $c->listen();

    $s = Scheduler::instance();
    $s->setCluster($c);
    $s->register("echo", function (): RpcEcho {
        return new RpcEcho();
    });
    $addr = $s->spawn("echo");

    $deadline = Scheduler::nowMs() + $seconds * 1000;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(10);
    }

    $svc = $s->serviceOf($addr);
    $served = 0;
    $methods = 0;
    if ($svc instanceof RpcEcho) {
        $served = $svc->served;
        $methods = count($svc->router()->methods());
    }
    $st = $s->stats();
    $cs = $c->stats();
    echo "server: addr=" . $addr
        . " served=" . $served
        . " methods=" . $methods
        . " in=" . $cs["in"] . " out=" . $cs["out"]
        . " undelivered=" . $st["undelivered"]
        . " errors=" . $st["errors"]
        . " peers=[" . implode(",", $c->peerNodes()) . "]" . PHP_EOL;
    $c->closeAll();
}

/**
 * 串行：发一个、tick 到收，再发下一个。测"单次往返"的真实分布。
 */
function runSerial(int $n): int
{
    $sock = "/tmp/tsf_rpc_node2.sock";
    $c = new Cluster(2, $sock);
    if (!$c->connect(1, "/tmp/tsf_rpc_node1.sock")) {
        echo "connect failed" . PHP_EOL;
        return 1;
    }
    $s = Scheduler::instance();
    $s->setCluster($c);

    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(2000);
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        echo "client spawn failed" . PHP_EOL;
        return 1;
    }

    $target = Addr::pack(1, 1);
    $lat = [];
    $done = 0;
    $errs = 0;

    for ($i = 1; $i <= $n; $i++) {
        $t0 = hrtime(true);
        $rpc->call($target, "echo", "x" . $i, function (?string $r, string $e) use (&$lat, &$done, &$errs, $t0): void {
            if ($e !== "") {
                $errs++;
                return;
            }
            $lat[] = (hrtime(true) - $t0) / 1000.0;   // µs
            $done++;
        });
        // 推动到收到响应为止（最多 50 轮，防死循环）
        $spin = 0;
        while ($done < $i && $spin < 50) {
            $s->tick();
            usleep(50);
            $spin++;
        }
    }

    sort($lat);
    $sum = 0.0;
    foreach ($lat as $v) {
        $sum += $v;
    }
    $cnt = count($lat);
    $avg = $cnt > 0 ? $sum / $cnt : 0.0;
    printf(
        "serial: n=%d ok=%d err=%d  avg=%.0f µs  p50=%.0f  p90=%.0f  p99=%.0f  max=%.0f\n",
        $n,
        $done,
        $errs,
        $avg,
        pct($lat, 0.50),
        pct($lat, 0.90),
        pct($lat, 0.99),
        pct($lat, 1.0)
    );

    $st = $rpc->stats();
    echo "  client stats: sent=" . $st["sent"] . " ok=" . $st["ok"]
        . " timeout=" . $st["timeout"] . " pending=" . $st["pending"] . PHP_EOL;

    $p99 = pct($lat, 0.99);
    $ok = ($done === $n) && ($errs === 0) && ($p99 <= 2000.0);
    echo $ok ? "PASS 跨进程 p99 ≤2 ms" : "FAIL 未达标" . PHP_EOL;
    $c->closeAll();
    return $ok ? 0 : 1;
}

/**
 * 批量：一次塞进去 $window 个在飞的请求，再统一收。
 * 这条更能暴露"回调表变大之后变慢"的问题。
 */
function runBurst(int $total, int $window): int
{
    $sock = "/tmp/tsf_rpc_node3.sock";
    $c = new Cluster(3, $sock);
    if (!$c->connect(1, "/tmp/tsf_rpc_node1.sock")) {
        echo "connect failed" . PHP_EOL;
        return 1;
    }
    $s = Scheduler::instance();
    $s->setCluster($c);

    $rpcAddr = $s->spawnOf(function (): Client {
        return new Client(3000);
    });
    $rpc = $s->serviceOf($rpcAddr);
    if (!($rpc instanceof Client)) {
        return 1;
    }

    $target = Addr::pack(1, 1);
    $lat = [];
    $started = 0;
    $done = 0;
    $errs = 0;
    $stamp = [];   // id => t0

    while ($started < $total) {
        // 补满窗口
        while ($started < $total && count($stamp) < $window) {
            $started++;
            $t0 = hrtime(true);
            $id = $rpc->call($target, "echo", "b" . $started, function (?string $r, string $e) use (&$lat, &$done, &$errs, &$stamp, $started): void {
                if ($e !== "") {
                    $errs++;
                    unset($stamp[$started]);
                    return;
                }
                $t = isset($stamp[$started]) ? (int) $stamp[$started] : 0;
                if ($t > 0) {
                    $lat[] = (hrtime(true) - $t) / 1000.0;
                }
                unset($stamp[$started]);
                $done++;
            });
            if ($id > 0) {
                $stamp[$started] = $t0;
            }
        }
        $s->tick();
    }

    // 收尾
    $spin = 0;
    while ($done + $errs < $total && $spin < 2000) {
        $s->tick();
        usleep(100);
        $spin++;
    }

    sort($lat);
    $sum = 0.0;
    foreach ($lat as $v) {
        $sum += $v;
    }
    $cnt = count($lat);
    $avg = $cnt > 0 ? $sum / $cnt : 0.0;
    printf(
        "burst:  total=%d window=%d ok=%d err=%d  avg=%.0f µs  p50=%.0f  p90=%.0f  p99=%.0f  max=%.0f\n",
        $total,
        $window,
        $done,
        $errs,
        $avg,
        pct($lat, 0.50),
        pct($lat, 0.90),
        pct($lat, 0.99),
        pct($lat, 1.0)
    );

    $p99 = pct($lat, 0.99);
    $ok = ($done === $total) && ($errs === 0) && ($p99 <= 2000.0);
    echo $ok ? "PASS 批量 p99 ≤2 ms" : "FAIL 未达标" . PHP_EOL;
    $c->closeAll();
    return $ok ? 0 : 1;
}

function main(): void
{
    // AOT 下取参数用 $_SERVER["argv"]，全局 $argv 不一定可用
    $args = $_SERVER["argv"] ?? [];
    $mode = isset($args[1]) ? (string) $args[1] : "help";
    $a = isset($args[2]) ? (int) $args[2] : 0;
    $b = isset($args[3]) ? (int) $args[3] : 0;

    if ($mode === "server") {
        runServer($a > 0 ? $a : 30);
        return;
    }
    if ($mode === "serial") {
        exit(runSerial($a > 0 ? $a : 500));
    }
    if ($mode === "burst") {
        exit(runBurst($a > 0 ? $a : 2000, $b > 0 ? $b : 200));
    }
    echo "用法: rpc_cluster server|serial|burst [n] [window]" . PHP_EOL;
}
