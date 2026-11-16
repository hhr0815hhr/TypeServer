<?php

use Probe\Message;

function bench(string $name, int $n, \Closure $fn): void
{
    for ($i = 0; $i < 2000; $i++) {
        $fn();
    }
    $t0 = hrtime(true);
    $sink = 0;
    for ($i = 0; $i < $n; $i++) {
        $sink += $fn();
    }
    $t1 = hrtime(true);
    echo "  " . str_pad($name, 36) . round(($t1 - $t0) / $n) . " ns" . PHP_EOL;
    if ($sink === -1) {
        echo "";
    }
}

/** 数组 + 头指针：出队只移动下标，满了再压缩 */
function qArrayHead(int $n): int
{
    $t0 = hrtime(true);
    $q = [];
    for ($i = 0; $i < $n; $i++) {
        $q[] = $i;
    }
    $head = 0;
    $len = count($q);
    $sum = 0;
    while ($head < $len) {
        $sum += $q[$head];
        $head++;
    }
    $t1 = hrtime(true);
    if ($sum < 0) {
        echo "";
    }
    return $t1 - $t0;
}

/** 数组 + array_shift（每次 O(n) 搬移，预期很差） */
function qArrayShift(int $n): int
{
    $t0 = hrtime(true);
    $q = [];
    for ($i = 0; $i < $n; $i++) {
        $q[] = $i;
    }
    $sum = 0;
    while ($q !== []) {
        $sum += array_shift($q);
    }
    $t1 = hrtime(true);
    if ($sum < 0) {
        echo "";
    }
    return $t1 - $t0;
}

/** SplQueue：C 实现的链式队列 */
function qSplQueue(int $n): int
{
    $t0 = hrtime(true);
    $q = new \SplQueue();
    for ($i = 0; $i < $n; $i++) {
        $q->enqueue($i);
    }
    $sum = 0;
    while (!$q->isEmpty()) {
        $sum += $q->dequeue();
    }
    $t1 = hrtime(true);
    if ($sum < 0) {
        echo "";
    }
    return $t1 - $t0;
}

function main(): void
{
    $n = 200000;

    echo "== 基础操作 ==" . PHP_EOL;
    bench("hrtime(true)", $n, function () { return hrtime(true) % 7; });
    bench("time()", $n, function () { return time() % 7; });
    bench("new Message(纯标量)", $n, function () { return (new Message(1, 1001, "x"))->msgId; });
    bench("new Message(带 hrtime)", $n, function () { return (new Message(1, 1001, "x", 0, hrtime(true)))->msgId; });

    echo PHP_EOL . "== 队列往返（入队 n 条后全部出队，含入队成本）==" . PHP_EOL;
    $sizes = [10000, 100000];
    foreach ($sizes as $size) {
        $per = qArrayHead($size) / $size;
        echo "  " . str_pad("array+head    n=" . $size, 30) . "单条 " . round($per) . " ns" . PHP_EOL;
        $per = qSplQueue($size) / $size;
        echo "  " . str_pad("SplQueue      n=" . $size, 30) . "单条 " . round($per) . " ns" . PHP_EOL;
        $per = qArrayShift($size) / $size;
        echo "  " . str_pad("array_shift   n=" . $size, 30) . "单条 " . round($per) . " ns" . PHP_EOL;
    }

    echo PHP_EOL . "== 结构可用性 ==" . PHP_EOL;
    echo "  SplQueue: " . (class_exists("SplQueue") ? "yes" : "no") . PHP_EOL;
    echo "  SplFixedArray: " . (class_exists("SplFixedArray") ? "yes" : "no") . PHP_EOL;
    echo "  SplPriorityQueue: " . (class_exists("SplPriorityQueue") ? "yes" : "no") . PHP_EOL;
}
