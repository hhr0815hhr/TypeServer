<?php

use Swoole\Coroutine;
use Swoole\Runtime;
use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 协程规模：几千个服务同时等 I/O，内存和延迟各是多少。
 *
 * ## 要回答的问题
 *
 * 框架推荐"一房一 Service"，一局结束前每个房间都可能挂着一个等 redis/pdo 的协程。
 * 那么：
 *   1 N 个协程同时存活，占多少 RSS（协程栈是 C 层 mmap，**不在 PHP 堆里**，
 *     所以 `memory_get_usage` 看不到它，必须读 /proc/self/status）
 *   2 全部完成的总耗时，是并发（≈50ms）还是排到队尾（≈N×50ms）
 *   3 创建 N 个协程本身要多久（`tick()` 里的 dispatchAsync）
 *
 * ## 为什么同步对照只跑 50 个
 *
 * 同步模式下 5000 × 50ms = 250 秒，跑不完。要验证的命题是"串行还是并发"，
 * 50 个就够看出量级（预期 2.5s vs 50ms，差 50 倍）。
 */

const WAIT_MS = 50;

final class Sleeper extends Service
{
    public static int $done = 0;

    public static float $sumMs = 0.0;

    public function onMessage(Message $m): void
    {
        $t0 = hrtime(true);
        // 协程模式下 SWOOLE_HOOK_SLEEP 让 usleep 变成"让出"；同步模式下真阻塞
        usleep(WAIT_MS * 1000);
        self::$sumMs += (hrtime(true) - $t0) / 1000000.0;
        self::$done++;
    }
}

/** 当前进程的常驻内存（KB）。协程栈不算在 memory_get_usage 里，只能读这个 */
function rssKb(): int
{
    $status = (string) file_get_contents("/proc/self/status");
    if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $m)) {
        return (int) $m[1];
    }
    return 0;
}

function reset_(): void
{
    Sleeper::$done = 0;
    Sleeper::$sumMs = 0.0;
    Scheduler::reset();
}

/** 建 N 个服务、各投一条消息，返回 [总耗时ms, tick耗时us, 基线RSS, 峰值RSS] */
function runOnce(int $n, bool $coroutine): array
{
    reset_();
    $s = Scheduler::instance();
    $s->setCoroutine($coroutine);
    // 条数预算放宽，让一个 tick 就能把消息全部分发出去
    $s->setBudget(100000);

    $addrs = [];
    for ($i = 0; $i < $n; $i++) {
        $addrs[] = $s->spawnOf(function (): Service {
            return new Sleeper();
        });
    }
    foreach ($addrs as $a) {
        $s->deliverAs(1, $a, 1, "");
    }

    $rssBefore = rssKb();
    $t0 = hrtime(true);
    $s->tick();
    $tickUs = intdiv(hrtime(true) - $t0, 1000);
    $rssAfter = rssKb();

    // 等全部完成。协程模式下必须让出，否则主协程会一直占着
    $deadline = hrtime(true) + 30000000000;
    if ($coroutine) {
        while (Sleeper::$done < $n && hrtime(true) < $deadline) {
            Coroutine::sleep(0.005);
        }
    } else {
        // 同步模式：tick 已经跑完了，不用等
    }
    $totalMs = (hrtime(true) - $t0) / 1000000.0;
    return [$totalMs, $tickUs, $rssBefore, $rssAfter];
}

function main(): void
{
    Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

    // 必须在 Coroutine\run 里跑：dispatchAsync 用的是 swoole_coroutine_create，
    // 没有运行中的调度器时会直接失败（现象是 tick 返回 0、一条消息都没处理）
    \Swoole\Coroutine\run(function (): void {
        echo "== 协程规模 ==" . PHP_EOL;
        echo "  每次等待 " . WAIT_MS . " ms（模拟一次 redis/pdo 往返）" . PHP_EOL;

        // ---- 同步对照：50 个串行 ----
        $sync = runOnce(50, false);
        echo PHP_EOL . "  [对照] 同步 50 个：总耗时 " . number_format($sync[0], 1) . " ms"
            . "（预期 ≈ " . (50 * WAIT_MS) . " ms，串行）" . PHP_EOL;

        // ---- 协程：逐级加倍 ----
        echo PHP_EOL . "  [协程]" . PHP_EOL;
        echo "  " . str_pad("并发数", 8) . str_pad("总耗时", 12) . str_pad("tick 分发", 12)
            . str_pad("RSS 增量", 12) . "每协程" . PHP_EOL;
        foreach ([100, 500, 1000, 2000, 5000] as $n) {
            $r = runOnce($n, true);
            $rssDelta = $r[3] - $r[2];
            $per = intdiv($rssDelta * 1024, $n);
            echo "  " . str_pad((string) $n, 8)
                . str_pad(number_format($r[0], 1) . " ms", 12)
                . str_pad($r[1] . " µs", 12)
                . str_pad($rssDelta . " KB", 12)
                . $per . " B" . PHP_EOL;
        }

        echo PHP_EOL . "  → 总耗时应该接近 " . WAIT_MS . " ms（并发），而不是 并发数 × " . WAIT_MS . " ms" . PHP_EOL;
        echo "  → 每协程 RSS 是栈 + 对象本身；协程栈不在 PHP 堆里，memory_get_usage 看不到" . PHP_EOL;
    });
}
