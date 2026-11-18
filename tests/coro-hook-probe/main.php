<?php

/**
 * 验证一个假设：redis 连接**在协程内建立**时，后续 ping 会不会让出 CPU。
 *
 * 背景：之前 coroutine-test 里协程模式没效果，怀疑是因为连接在 onStart（非协程）建的。
 * Swoole 的 hook 只对「协程内发起的 socket 操作」生效 —— 如果这个假设成立，
 * 那 pkg/redis 要改成「协程内 lazy connect」或「连接池」才谈得上异步。
 *
 * 这个探测只做一件事：同一个进程里，
 *   - 协程 A 在**协程内**建连并循环 ping（公网，RTT 约 31ms）
 *   - Timer 每 10ms 打点
 * 如果 A 的 ping 让出 CPU，打点间隔应稳定在 10ms 附近、无尖峰。
 *
 * 对照组（HOOK=0）不开 enableCoroutine，应该能看到尖峰。
 */

final class Probe
{
    /** @var array<int, float> */
    public static array $stamps = [];

    public static int $pings = 0;

    public static float $pingMs = 0.0;

    public static string $firstPing = "";
}

function main(): void
{
    $hook = getenv("HOOK") !== "0";
    echo "enableCoroutine: " . ($hook ? "开" : "关") . PHP_EOL;

    if ($hook) {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    }

    // 协程 A：在协程内建连，然后循环 ping
    \swoole_coroutine_create(function (): void {
        $r = new \Redis();
        $ok = false;
        try {
            $ok = $r->connect("127.0.0.1", 22379, 3.0);
            if ($ok) {
                $r->auth("");
            }
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            Probe::$firstPing = "连接失败";
            return;
        }

        $t0 = hrtime(true);
        $r->ping();
        $first = (hrtime(true) - $t0) / 1e6;
        Probe::$firstPing = round($first, 1) . " ms";

        $i = 0;
        while ($i < 12) {
            $t1 = hrtime(true);
            $r->ping();
            Probe::$pingMs += (hrtime(true) - $t1) / 1e6;
            Probe::$pings++;
            usleep(200000);     // 200ms，协程内 hook 会变成非阻塞
            $i++;
        }
    });

    // 每 10ms 打点
    \Swoole\Timer::tick(10, function (int $id): void {
        Probe::$stamps[] = microtime(true);
    });

    // 3 秒后统计
    \Swoole\Timer::after(3000, function () use ($hook): void {
        $stamps = Probe::$stamps;
        $n = count($stamps);
        echo "打点数: " . $n . PHP_EOL;
        echo "首次 ping: " . Probe::$firstPing . PHP_EOL;
        $pings = Probe::$pings;
        echo "ping 次数: " . $pings . "，平均 " . ($pings > 0 ? round(Probe::$pingMs / $pings, 1) : "-") . " ms" . PHP_EOL;

        if ($n < 10) {
            echo "采样太少" . PHP_EOL;
            exit(1);
        }

        $max = 0.0;
        $over20 = 0;
        $sum = 0.0;
        $cnt = 0;
        $i = 1;
        while ($i < $n) {
            $g = ($stamps[$i] - $stamps[$i - 1]) * 1000.0;
            if ($g > 0.0 && $g < 200.0) {
                $sum += $g;
                $cnt++;
                if ($g > $max) {
                    $max = $g;
                }
                if ($g > 20.0) {
                    $over20++;
                }
            }
            $i++;
        }
        $avg = $cnt > 0 ? $sum / $cnt : 0.0;
        echo "打点平均间隔: " . round($avg, 2) . " ms" . PHP_EOL;
        echo "打点最大间隔: " . round($max, 2) . " ms" . PHP_EOL;
        echo "超过 20ms: " . $over20 . " / " . $cnt . PHP_EOL;

        echo PHP_EOL . "结论: ";
        if ($hook) {
            if ($max < 20.0) {
                echo "假设成立 —— 协程内建连后 ping 不阻塞（最大 " . round($max, 1) . "ms）。"
                    . "pkg/redis 需要改成协程内建连/连接池。";
            } else {
                echo "假设不成立 —— 协程内建连仍然阻塞（最大 " . round($max, 1) . "ms）。"
                    . "hook 对 \\Redis 无效，协程化这条路走不通。";
            }
        } else {
            echo "对照组（未开 hook）最大 " . round($max, 1) . "ms";
        }
        echo PHP_EOL;
        exit(0);
    });
}
