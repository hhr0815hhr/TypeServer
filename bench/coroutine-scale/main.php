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
function runOnce(int $n, bool $coroutine, int $warmN = 0): array
{
    reset_();
    $s = Scheduler::instance();
    $s->setCoroutine($coroutine);
    // 条数预算放宽，让一个 tick 就能把消息全部分发出去
    $s->setBudget(100000);

    // 预热栈池：在 spawn 之前做，否则第一轮创建服务时就会顺手把池填了，
    // 冷热对比就失效了
    $warmed = 0;
    if ($warmN > 0) {
        $warmed = $s->warmCoroutines($warmN);
        Coroutine::sleep(0.02);
    }

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
    echo "    （预热 " . $warmed . " 个协程栈，" . $s->stats()["handled"] . " 条已处理）" . PHP_EOL;
    return [$totalMs, $tickUs, $rssBefore, $rssAfter];
}

function main(): void
{
    Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

    // 必须在 Coroutine\run 里跑：dispatchAsync 用的是 swoole_coroutine_create，
    // 没有运行中的调度器时会直接失败（现象是 tick 返回 0、一条消息都没处理）
    \Swoole\Coroutine\run(function (): void {
        // 冷热必须**分进程**测：同一个进程里第一轮创建协程时就把栈池填了，
        // 后面的轮次无论怎么写都是热的，对比就失去意义。用 WARM 环境变量控制
        $warm = 0;
        if (getenv("WARM") === "1") {
            $warm = 5000;
        }

        echo "== 协程规模：" . ($warm > 0 ? "栈池预热后" : "冷启动（池空）") . " ==" . PHP_EOL;
        echo "  5000 个服务各等 50 ms（模拟一次 redis/pdo 往返）" . PHP_EOL;

        $r = runOnce(5000, true, $warm);
        $per = $r[1] / 5000.0;
        echo "  总耗时 " . number_format($r[0], 1) . " ms" . PHP_EOL;
        echo "  tick 分发 " . number_format((float) $r[1]) . " µs（" . number_format($per, 2) . " µs/个）" . PHP_EOL;
        echo "  RSS 增量 " . ($r[3] - $r[2]) . " KB" . PHP_EOL;
        echo PHP_EOL . "  → 总耗时接近 50 ms 说明并发成立；分发成本 × 帧率就是容量上限" . PHP_EOL;
    });
}
