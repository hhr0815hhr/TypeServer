<?php

/**
 * 验证：开了 enableCoroutine，在 Swoole\Timer 回调里做 redis 调用，会不会阻塞事件循环。
 *
 * 方法：起一个 10ms 的 Timer 记录时间间隔。同时在另一个 Timer 里每 200ms 做一次 redis PING。
 * 如果 hook 生效（redis 调用不阻塞），间隔应稳定在 10ms 附近；
 * 如果没生效（阻塞），每次 PING 会插入一个 RTT 大小的尖峰。
 *
 * 你的 redis 在公网，RTT 几十毫秒，尖峰很容易看出来。
 */

use Swoole\Runtime;
use Swoole\Timer;

function main(): void
{
    $host = getenv("REDIS_HOST") ?: "127.0.0.1";
    $port = (int) (getenv("REDIS_PORT") ?: "6379");
    $auth = getenv("REDIS_AUTH") ?: "";

    echo "目标 redis: " . $host . ":" . $port . PHP_EOL;

    // 开 hook（这一步本身能否生效，就是我们要测的）
    $hooked = false;
    if (method_exists("Swoole\\Runtime", "enableCoroutine")) {
        $hooked = true;
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    }
    echo "enableCoroutine = " . ($hooked ? "已调用" : "不可用") . PHP_EOL;

    $redis = new \Redis();
    $ok = $redis->connect($host, $port, 3.0);
    if ($ok && $auth !== "") {
        $redis->auth($auth);
    }
    echo "redis 连接 = " . ($ok ? "ok" : "失败") . PHP_EOL;

    // 先测一次裸 RTT，作为尖峰的参考值
    $t0 = hrtime(true);
    $redis->ping();
    $bareRtt = (hrtime(true) - $t0) / 1e6;
    echo "单次 PING 耗时 = " . round($bareRtt, 2) . " ms" . PHP_EOL . PHP_EOL;

    $last = 0.0;
    $gaps = [];
    $pings = 0;
    $maxGap = 0.0;

    // 10ms 心跳
    Timer::tick(10, function (int $id) use (&$last, &$gaps, &$maxGap): void {
        $now = hrtime(true) / 1e6;
        if ($last > 0.0) {
            $gap = $now - $last;
            if ($gap > 100.0) {
                return;     // 首帧/GC 之类的异常值不算
            }
            $gaps[] = $gap;
            if ($gap > $maxGap) {
                $maxGap = $gap;
            }
        }
        $last = $now;
    });

    // 每 200ms 做一次 redis 调用（在 Timer 回调里 —— 不是协程）
    Timer::tick(200, function (int $id) use ($redis, &$pings): void {
        $redis->ping();
        $pings++;
    });

    // 3 秒后统计
    Timer::after(3000, function () use (&$gaps, &$pings, &$maxGap, $bareRtt): void {
        $n = count($gaps);
        echo "== 结果 ==" . PHP_EOL;
        echo "  10ms 心跳采样: " . $n . " 次，期间 redis 调用: " . $pings . " 次" . PHP_EOL;
        if ($n === 0) {
            echo "  没有采样，可能整个循环被阻塞了" . PHP_EOL;
            exit(1);
        }
        $sum = 0.0;
        $over20 = 0;
        foreach ($gaps as $g) {
            $sum += $g;
            if ($g > 20.0) {
                $over20++;
            }
        }
        $avg = $sum / $n;
        echo "  平均间隔: " . round($avg, 3) . " ms" . PHP_EOL;
        echo "  最大间隔: " . round($maxGap, 2) . " ms" . PHP_EOL;
        echo "  超过 20ms 的次数: " . $over20 . " / " . $n . PHP_EOL;
        echo PHP_EOL;

        // 判据：最大间隔接近 RTT 说明被阻塞；接近 10ms 说明 hook 生效
        $blocked = $maxGap > ($bareRtt * 0.6);
        if ($blocked) {
            echo "结论：**被阻塞**。最大间隔 " . round($maxGap, 2) . "ms 与单次 RTT "
                . round($bareRtt, 2) . "ms 同量级 —— Timer 回调不是协程，hook 对它无效。" . PHP_EOL;
        } else {
            echo "结论：**没被阻塞**。最大间隔 " . round($maxGap, 2)
                . "ms 远小于 RTT " . round($bareRtt, 2) . "ms —— hook 生效了。" . PHP_EOL;
        }
        exit(0);
    });
}
