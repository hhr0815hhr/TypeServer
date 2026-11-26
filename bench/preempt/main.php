<?php

use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * 一段纯 CPU 循环会把**其他所有协程**的定时器精度拉成它的时长吗？
 *
 * 这是"缺抢占"的精确表述。不是"能不能杀掉那个协程"，而是
 * "一个不让出的循环，会不会让整个进程的软实时全部失效"。
 *
 * ## 测法
 *
 * 协程 probe 每 5 ms 醒一次，记录相邻两次醒来之间的**实际间隔**。
 * 同时在中间插入一段纯 CPU 循环。如果调度是抢占式的，probe 的间隔应该
 * 始终接近 5 ms；协作式的（PHP 的现实）下，probe 会一直等到循环让出，
 * 最大间隔 ≈ 循环时长。
 *
 * ## 为什么不用 Coroutine::cancel
 *
 * 第一版用 cancel 测，数字不可信：`while ($i < 3000000000)` 被 AOT 优化掉了
 * （30 亿次不可能 150 ms 跑完），victim 早就结束，于是 cancel 返回 false。
 * 而且 cancel 的语义本来就是"在下一个让出点抛异常"——它压根没打算中断 CPU 代码，
 * 拿它证明"打不断"是循环论证。定时器精度是**外部可观测**的，骗不了人。
 */

/** 防优化：静态属性写有副作用，编译器消不掉 */
final class Counter
{
    public static int $n = 0;
}

/** 探针的统计结果（用对象传递，AOT 下闭包引用捕获不可靠） */
final class Stats
{
    public int $maxGap = 0;

    public int $samples = 0;

    public int $done = 0;
}

const PROBE_MS = 5;

const RUN_MS = 1000;

function run(bool $cpuBlock): void
{
    $st = new Stats();
    $t0 = hrtime(true);

    Coroutine::create(function () use ($t0, $st): void {
        $last = hrtime(true);
        $target = intdiv(RUN_MS, PROBE_MS);
        while ($st->samples < $target) {
            Coroutine::sleep(PROBE_MS / 1000.0);
            $now = hrtime(true);
            $gap = $now - $last;
            if ($gap > $st->maxGap) {
                $st->maxGap = $gap;
            }
            $last = $now;
            $st->samples++;
        }
        $st->done = 1;
    });

    if ($cpuBlock) {
        // 200 ms 后插入一段纯 CPU 循环
        Coroutine::sleep(0.2);
        $blockStart = hrtime(true);
        for ($i = 0; $i < 30000000; $i++) {
            Counter::$n++;
        }
        $blockMs = intdiv(hrtime(true) - $blockStart, 1000000);
        echo "  CPU 循环实际跑了 " . $blockMs . " ms" . PHP_EOL;
    }

    $deadline = hrtime(true) + (RUN_MS + 8000) * 1000000;
    while ($st->done === 0 && hrtime(true) < $deadline) {
        Coroutine::sleep(0.01);
    }

    echo "  probe 醒来 " . $st->samples . " 次，最大间隔 "
        . intdiv($st->maxGap, 1000000) . " ms（期望 " . PROBE_MS . " ms）" . PHP_EOL;
}

function main(): void
{
    Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

    echo "== 对照组：只有 probe，没有 CPU 循环 ==" . PHP_EOL;
    \Swoole\Coroutine\run(function (): void {
        run(false);
    });

    echo PHP_EOL . "== 实验组：中间插入 300 ms 纯 CPU 循环 ==" . PHP_EOL;
    \Swoole\Coroutine\run(function (): void {
        run(true);
    });

    echo PHP_EOL . "  → 若实验组的最大间隔 ≈ CPU 循环时长，说明调度是协作式的：" . PHP_EOL;
    echo "    一个不让出的循环会把**所有**其他协程的定时器一起拖住，" . PHP_EOL;
    echo "    而外部没有任何办法把一个 CPU 循环从进程里摘出去" . PHP_EOL;
}
