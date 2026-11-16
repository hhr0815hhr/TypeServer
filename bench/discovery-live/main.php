<?php

/**
 * 真实的收敛验证：一个进程注册并心跳，另一个进程观察；杀掉前者，量多久发现。
 *
 * 为什么不在单进程里模拟：单进程里"停心跳"和"进程死了"是两件事——
 * 前者还能靠代码保持其他状态，后者连文件描述符都没了。
 * 只有真 kill 才能验证"没有 onStop、没有注销、纯靠 TTL"这条路径。
 */

use TSF\Discovery\FileRegistry;
use TSF\Discovery\LocalNode;
use TSF\Discovery\NodeInfo;
use TSF\Service\Scheduler;

const DIR = "/tmp/tsf_disc_live";
const TTL_MS = 3000;
const BEAT_MS = 500;
const NODE_ID = 1;

function runNode(int $seconds): void
{
    $reg = new FileRegistry(DIR, TTL_MS);
    $s = Scheduler::instance();
    $info = new NodeInfo(NODE_ID, "/tmp/tsf_disc_node1.sock", "json", ["role" => "gw"]);
    $s->spawnOf(function () use ($reg, $info): LocalNode {
        return new LocalNode($reg, $info, BEAT_MS);
    });

    echo "node: registered id=" . NODE_ID . " beat=" . BEAT_MS . "ms ttl=" . TTL_MS . "ms" . PHP_EOL;

    $deadline = Scheduler::nowMs() + $seconds * 1000;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(5000);
    }
    // 正常退出会走 onStop → 注销；脚本结束前不会被 kill 时才走到这
    echo "node: exiting normally" . PHP_EOL;
}

/**
 * 观察者：每 100ms 扫一次，量"最后一次心跳 → 判定死亡"的间隔。
 *
 * 这个指标才是 TTL 的语义。之前用"最后一轮扫描 → 下一轮"算出 101ms，
 * 那是轮询间隔，不是收敛时间——`lastAliveAt` 每轮都在更新，
 * 它会一直跟着"现在"走，直到节点消失那一刻才停。看起来很快，其实毫无意义。
 */
function runWatch(int $maxSeconds): void
{
    $reg = new FileRegistry(DIR, TTL_MS);
    $start = Scheduler::nowMs();
    $lastSeenOfNode = 0;
    $sawEver = false;
    $maxAgeWhileAlive = 0;

    while (true) {
        $alive = $reg->alive();
        $n = count($alive);
        $now = Scheduler::nowMs();
        // 算心跳年龄必须用**墙钟**：`.beat` 里存的是 microtime，
        // 而 Scheduler::nowMs() 是 hrtime 单调时钟，两者基准不同
        $wallNow = (int) (microtime(true) * 1000);

        if ($n > 0) {
            $sawEver = true;
            foreach ($alive as $node) {
                $lastSeenOfNode = $node->lastSeen;
                $age = $wallNow - $node->lastSeen;
                if ($age > $maxAgeWhileAlive) {
                    $maxAgeWhileAlive = $age;
                }
            }
        } elseif ($sawEver && $lastSeenOfNode > 0) {
            $judgedAfter = $wallNow - $lastSeenOfNode;
            echo "watch: 最后一次心跳后又过了 " . $judgedAfter . "ms 才判定死亡" . PHP_EOL;
            echo "watch: 活着期间观察到的最大心跳年龄 " . $maxAgeWhileAlive . "ms" . PHP_EOL;

            $ok = $judgedAfter >= TTL_MS && $judgedAfter <= TTL_MS + 800;
            if ($ok) {
                echo "PASS 收敛 " . $judgedAfter . "ms（TTL=" . TTL_MS . "，轮询余量 800ms）" . PHP_EOL;
            } else {
                echo "FAIL 收敛 " . $judgedAfter . "ms 不在 [" . TTL_MS . "," . (TTL_MS + 800) . "] 区间" . PHP_EOL;
            }
            return;
        }

        if ($now - $start > $maxSeconds * 1000) {
            echo "watch: 超时，saw_ever=" . ($sawEver ? "1" : "0") . PHP_EOL;
            echo "FAIL 没有观察到消失" . PHP_EOL;
            return;
        }
        usleep(100000);
    }
}

function main(): void
{
    $args = $_SERVER["argv"] ?? [];
    $mode = isset($args[1]) ? (string) $args[1] : "help";
    $a = isset($args[2]) ? (int) $args[2] : 0;

    if ($mode === "node") {
        runNode($a > 0 ? $a : 60);
        return;
    }
    if ($mode === "watch") {
        runWatch($a > 0 ? $a : 10);
        return;
    }
    echo "用法: discovery_live node|watch [seconds]" . PHP_EOL;
}
