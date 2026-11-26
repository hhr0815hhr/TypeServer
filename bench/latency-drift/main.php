<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 长跑延迟漂移：连续跑几十秒，调度间隔的 p99 会不会越来越差。
 *
 * ## 为什么要测
 *
 * 内存碎片、定时器堆的垃圾、哈希表的历史峰值，都只在长时间运行后才显形。
 * 这类问题在压测里完全看不到（压测通常几十秒就结束），线上表现为
 * "服务跑了一天才开始卡"，而且重启就好——最难归因的一种。
 *
 * ## 测法
 *
 * 一个服务每 10 ms 给自己投一条消息，主循环每 1 ms tick 一次（贴近真实节奏）。
 * 记录相邻两条消息的**实际间隔**，按 5 秒一个窗口统计 p99。
 *
 * 期望间隔 10 ms，加上 tick 粒度后通常 10–11 ms。判据是：
 * **p99 在各窗口之间基本持平**。单调上升就是退化。
 */

const INTERVAL_MS = 10;

const WINDOW_SEC = 5;

const TOTAL_SEC = 30;

final class Pinger extends Service
{
    public static int $lastNs = 0;

    /** @var array<int, int> 当前窗口的间隔样本（微秒） */
    public static array $gaps = [];

    public function onStart(): void
    {
        $this->interval(INTERVAL_MS, 1);
    }

    public function onMessage(Message $m): void
    {
        $now = hrtime(true);
        if (self::$lastNs > 0) {
            self::$gaps[] = intdiv($now - self::$lastNs, 1000);
        }
        self::$lastNs = $now;
    }
}

/** @param array<int, int> $xs */
function percentile(array $xs, int $pct): int
{
    $n = count($xs);
    if ($n === 0) {
        return 0;
    }
    // 插入排序：每个窗口只有几百个样本，够用，也避免 AOT 下 sort 的类型推断问题
    for ($i = 1; $i < $n; $i++) {
        $v = $xs[$i];
        $j = $i - 1;
        while ($j >= 0 && $xs[$j] > $v) {
            $xs[$j + 1] = $xs[$j];
            $j--;
        }
        $xs[$j + 1] = $v;
    }
    $idx = intdiv($n * $pct, 100);
    if ($idx >= $n) {
        $idx = $n - 1;
    }
    return $xs[$idx];
}

function main(): void
{
    echo "== 长跑延迟漂移 ==" . PHP_EOL;
    echo "  每 " . INTERVAL_MS . " ms 一条自投消息，主循环 1 ms tick 一次，共 "
        . TOTAL_SEC . " 秒" . PHP_EOL . PHP_EOL;

    Scheduler::reset();
    $s = Scheduler::instance();

    $addr = $s->spawnOf(function (): Service {
        return new Pinger();
    });
    // 让 interval 注册上
    $s->tick();

    echo "  " . str_pad("窗口", 8) . str_pad("样本", 8) . str_pad("p50", 10)
        . str_pad("p99", 10) . "最大" . PHP_EOL;

    $start = hrtime(true);
    $windowEnd = $start + WINDOW_SEC * 1000000000;
    $end = $start + TOTAL_SEC * 1000000000;
    $win = 1;
    $firstP99 = 0;
    $lastP99 = 0;

    while (hrtime(true) < $end) {
        $s->tick();
        usleep(1000);

        if (hrtime(true) >= $windowEnd) {
            $gaps = Pinger::$gaps;
            Pinger::$gaps = [];
            $p50 = percentile($gaps, 50);
            $p99 = percentile($gaps, 99);
            $max = 0;
            foreach ($gaps as $g) {
                if ($g > $max) {
                    $max = $g;
                }
            }
            echo "  " . str_pad("#" . $win, 8) . str_pad((string) count($gaps), 8)
                . str_pad($p50 . " µs", 10) . str_pad($p99 . " µs", 10) . $max . " µs" . PHP_EOL;
            if ($win === 1) {
                $firstP99 = $p99;
            }
            $lastP99 = $p99;
            $win++;
            $windowEnd += WINDOW_SEC * 1000000000;
        }
    }

    echo PHP_EOL . "  → 判据：p99 各窗口持平。首窗口 " . $firstP99 . " µs，末窗口 "
        . $lastP99 . " µs" . PHP_EOL;
    echo "  → 期望间隔 " . INTERVAL_MS . " ms；p99 明显高于它说明 tick 粒度或调度有抖动" . PHP_EOL;
}
