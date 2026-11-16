<?php

// AOI 邻居查询的 A/B 基准。
//
// 目的不是给 neighbors() 打分，而是回答一个具体问题：
// **每次调用分配一个 $out 数组的固定开销，在总耗时里占多少？**
//
// 方法：同一份实现，只改「每个格子有多少人」。
//   - 稀疏：9 格里几乎没人 → 耗时近似等于「分配 + 9 次循环 + 查找」
//   - 密集：9 格里几百人 → 耗时主要由「逐元素哈希写入」决定
// 两条直线一减，就能把固定开销和逐元素开销拆开，也就能判断
// "换成 std::vector 复用缓冲" 值不值——如果固定开销只占小头，不值。

use TSF\Aoi\Grid;

/**
 * @param string $mode dense | mid | sparse
 */
function build(int $n, string $mode): Grid
{
    $g = new Grid(64, 100, 100);
    $i = 0;
    while ($i < $n) {
        if ($mode === "dense") {
            // 全挤在中心一格
            $g->insert($i, 100, 100);
        } elseif ($mode === "mid") {
            // 中心 5x5 格，每格 n/25 人。
            // x 和 y 都必须从「i 落在哪一格」推出来：之前直接用 intdiv($i,5) 当 y，
            // 500 个实体的 y 会一直涨到 row 99 之外被夹回边界，测出来的密度
            // 跟设计值完全不是一回事（期望 180 个邻居，实际只有 6 个）。
            $cell = $i % 25;
            $g->insert($i, 100 + ($cell % 5) * 64, 100 + intdiv($cell, 5) * 64);
        } else {
            // 满场散开
            $g->insert($i, ($i * 37) % 6000, ($i * 91) % 6000);
        }
        $i++;
    }
    return $g;
}

/** 测 neighbors() 的 ns/次，并把结果真的用掉（别让编译器把整段当死代码） */
function benchNeighbors(Grid $g, int $uid, int $times): float
{
    $sum = 0;
    $t0 = hrtime(true);
    $i = 0;
    while ($i < $times) {
        $list = $g->neighbors($uid);
        $sum += count($list);
        $i++;
    }
    $t1 = hrtime(true);
    if ($sum < -1) {
        echo "不可能: " . $sum . PHP_EOL;
    }
    return (float) ($t1 - $t0) / (float) $times;
}

/** 对照：按 key 直接取（省掉一次 cellOf 查找） */
function benchOfKey(Grid $g, int $uid, int $times): float
{
    $key = $g->cellKeyOf($uid);
    $sum = 0;
    $t0 = hrtime(true);
    $i = 0;
    while ($i < $times) {
        $list = $g->neighborsOfKey($key);
        $sum += count($list);
        $i++;
    }
    $t1 = hrtime(true);
    if ($sum < -1) {
        echo "不可能: " . $sum . PHP_EOL;
    }
    return (float) ($t1 - $t0) / (float) $times;
}

function report(string $label, Grid $g, int $uid, int $times): void
{
    // 先跑一遍预热（首次调用会触碰类初始化与内存分配器）
    $warm = $g->neighbors($uid);
    $avg = (float) count($warm);

    $ns = benchNeighbors($g, $uid, $times);
    $nsKey = benchOfKey($g, $uid, $times);

    printf(
        "%-8s 邻居约 %6.1f 个   neighbors %9.1f ns   OfKey %9.1f ns   %6.2f ns/邻居\n",
        $label,
        $avg,
        $ns,
        $nsKey,
        $avg > 0.0 ? $ns / $avg : 0.0
    );
}

function main(): void
{
    $times = 20000;

    $dense = build(500, "dense");
    $mid = build(500, "mid");
    $sparse = build(500, "sparse");

    echo "500 个实体，测 " . $times . " 次/组" . PHP_EOL;
    echo "--------------------------------------------------------------" . PHP_EOL;

    report("密集", $dense, 1, $times);
    report("中", $mid, 1, $times);
    report("稀疏", $sparse, 1, $times);

    echo "--------------------------------------------------------------" . PHP_EOL;
    echo "解读：稀疏组的耗时 ≈ 每次分配的固定开销 + 9 格循环；" . PHP_EOL;
    echo "      密集组减去稀疏组，除以邻居数，才是逐元素的真实成本。" . PHP_EOL;
}
