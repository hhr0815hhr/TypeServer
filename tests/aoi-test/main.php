<?php

/**
 * pkg/aoi 回归。
 *
 * ## 为什么这个套件最重要的不是"功能对不对"
 *
 * AOI 算错的典型表现**不是崩溃**，而是"视野里少一个人"：
 * 线上表现为"偶发看不到队友"、"技能没打中明明在旁边的怪"。
 * 没有崩溃日志、没有报错，只能靠玩家反馈——这类 bug 一旦漏出去，几乎没法排查。
 *
 * 所以这里不测"某个方法返回什么"，而是**用暴力法对拍**：
 * 随机撒 N 个实体，按定义（3×3 格 / Chebyshev 距离）直接算出"应该能看到谁"，
 * 再和 `neighbors()` / `around()` 的返回逐一比对。不依赖对实现的任何假设。
 *
 * 覆盖的四类"错了也不崩"：
 *   1. 坐标越界夹取（负坐标、超过 maxX/maxY）
 *   2. 格子边界（x=100 且 cellSize=100，属于第 1 格不是第 0 格）
 *   3. 移动后旧格清理（不清理会让"已经离开的人"一直可见）
 *   4. 3×3 / 半径邻域的对角覆盖（最容易漏的就是对角）
 */

use TSF\Aoi\Grid;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  PASS  " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL  " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|string|bool $got, int|string|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/** 按定义算：格子 (cx,cy) 周围 3×3 内所有实体（含自己那一格） */
function bruteNeighbors(array $pos, int $cellSize, int $cols, int $rows, int $cx, int $cy): array
{
    $out = [];
    foreach ($pos as $uid => $p) {
        $px = intdiv($p[0], $cellSize);
        $py = intdiv($p[1], $cellSize);
        if ($px >= $cols) {
            $px = $cols - 1;
        }
        if ($py >= $rows) {
            $py = $rows - 1;
        }
        $dx = $px - $cx;
        $dy = $py - $cy;
        if ($dx < 0) {
            $dx = -$dx;
        }
        if ($dy < 0) {
            $dy = -$dy;
        }
        if ($dx <= 1 && $dy <= 1) {
            $out[$uid] = $uid;
        }
    }
    return $out;
}

function sameSet(array $a, array $b): bool
{
    if (count($a) !== count($b)) {
        return false;
    }
    foreach ($a as $k => $_) {
        if (!isset($b[$k])) {
            return false;
        }
    }
    return true;
}

function caseBasic(): void
{
    echo PHP_EOL . "== 基础：插入 / 移除 / 计数 ==" . PHP_EOL;
    $g = new Grid(100, 10, 10, 999, 999);

    T::ok($g->insert(1, 50, 50), "插入成功");
    T::ok(!$g->insert(1, 60, 60), "重复插入返回 false（uid 唯一）");
    T::eq($g->entityCount(), 1, "实体数 1");
    T::eq($g->cellCount(), 1, "占用格数 1");

    T::ok($g->remove(1), "移除成功");
    T::ok(!$g->remove(1), "重复移除返回 false");
    T::eq($g->entityCount(), 0, "实体数归零");
    T::eq($g->cellCount(), 0, "空格子被回收（不留空数组）");
    T::eq($g->cellKeyOf(1), -1, "不存在的实体返回 -1");
}

function caseBounds(): void
{
    echo PHP_EOL . "== 坐标边界（夹取而不是越界）==" . PHP_EOL;
    $g = new Grid(100, 10, 10, 999, 999);

    // 负坐标夹到 0
    T::eq($g->keyOf(-500, -500), 0, "负坐标夹到 (0,0)");
    // 超出 max 夹到边界
    T::eq($g->keyOf(99999, 99999), 99, "超上限夹到最后一格（9*10+9）");
    // 格子边界：x=100 属于第 1 格
    T::eq($g->keyOf(100, 0), 1, "x=100 是第 1 格（左闭右开）");
    T::eq($g->keyOf(99, 0), 0, "x=99 是第 0 格");
    T::eq($g->keyOf(0, 100), 10, "y=100 是第 1 行");
}

function caseMove(): void
{
    echo PHP_EOL . "== 移动（返回值语义 + 旧格清理）==" . PHP_EOL;
    $g = new Grid(100, 10, 10, 999, 999);
    $g->insert(1, 10, 10);          // 格 0

    T::eq($g->move(1, 20, 20), -1, "同格移动返回 -1");
    T::eq($g->cellCount(), 1, "同格移动不新建格子");

    $old = $g->move(1, 150, 10);    // 格 1
    T::eq($old, 0, "跨格移动返回旧 key（格 0）");
    T::eq($g->cellCount(), 1, "旧格被回收（不是 2 个格）");
    T::eq($g->cellKeyOf(1), 1, "实体在新格");

    T::eq($g->move(99, 0, 0), -2, "移动不存在的实体返回 -2");
}

/**
 * 核心：3×3 邻域对拍。
 *
 * 特别要覆盖**对角**：只查上下左右的实现，会漏掉四个角上的实体，
 * 而"站在斜对角看不见"这种问题在游戏里很难被察觉。
 */
function caseNeighborsMatchBrute(): void
{
    echo PHP_EOL . "== 3×3 邻域：与暴力法对拍 ==" . PHP_EOL;

    $cellSize = 50;
    $cols = 20;
    $rows = 20;
    $g = new Grid($cellSize, $cols, $rows, 999, 999);

    mt_srand(20261123);
    $pos = [];
    for ($uid = 1; $uid <= 400; $uid++) {
        $x = mt_rand(0, 999);
        $y = mt_rand(0, 999);
        $g->insert($uid, $x, $y);
        $pos[$uid] = [$x, $y];
    }

    $bad = 0;
    $checked = 0;
    foreach ($pos as $eid => $p) {
        $cx = intdiv($p[0], $cellSize);
        $cy = intdiv($p[1], $cellSize);
        if ($cx >= $cols) {
            $cx = $cols - 1;
        }
        if ($cy >= $rows) {
            $cy = $rows - 1;
        }
        $want = bruteNeighbors($pos, $cellSize, $cols, $rows, $cx, $cy);
        $got = $g->neighbors($eid);
        $checked++;
        if (!sameSet($want, $got)) {
            $bad++;
            if ($bad <= 3) {
                echo "        差异 uid=" . $eid
                    . " want=" . count($want) . " got=" . count($got) . PHP_EOL;
            }
        }
    }
    T::eq($bad, 0, "400 个实体的 3×3 邻域全部一致（检查 " . $checked . " 个）");

    // 对角必须可见：一个在 (0,0)，一个在相邻格的对角 (cellSize, cellSize)
    $g2 = new Grid(100, 10, 10, 999, 999);
    $g2->insert(1, 10, 10);      // 格 (0,0)
    $g2->insert(2, 110, 110);    // 格 (1,1) —— 对角
    T::ok(isset($g2->neighbors(1)[2]), "对角相邻的实体互相可见");
    T::ok(isset($g2->neighbors(2)[1]), "反向也可见（对称）");

    // 隔一格必须不可见
    $g2->insert(3, 210, 210);    // 格 (2,2)
    T::ok(!isset($g2->neighbors(1)[3]), "隔一格看不到");
}

function caseAroundRadius(): void
{
    echo PHP_EOL . "== 半径邻域：必须**不漏**（允许多）==" . PHP_EOL;

    // radius 的单位是**世界坐标**，不是格数（`ringsFor` 里是 intdiv(radius, cellSize) + 1）。
    // 这是最容易传错的一个参数：按格数传时 radius=1 恰好也对，于是错误被掩盖到
    // radius 变大才暴露。所以这里按"坐标距离"构造，并**只断言不漏判**。
    $cellSize = 100;
    $cols = 10;
    $rows = 10;
    $g = new Grid($cellSize, $cols, $rows, 999, 999);
    mt_srand(777);

    $pos = [];
    for ($i = 1; $i <= 120; $i++) {
        $x = mt_rand(0, 999);
        $y = mt_rand(0, 999);
        $g->insert($i, $x, $y);
        $pos[$i] = [$x, $y];
    }

    foreach ([50, 100, 150, 300] as $radius) {
        $missed = 0;
        $totalInRange = 0;
        foreach ($pos as $eid => $p) {
            $got = $g->around($eid, $radius);
            foreach ($pos as $other => $op) {
                $dx = $op[0] - $p[0];
                if ($dx < 0) {
                    $dx = -$dx;
                }
                $dy = $op[1] - $p[1];
                if ($dy < 0) {
                    $dy = -$dy;
                }
                $cheb = $dx > $dy ? $dx : $dy;
                if ($cheb <= $radius) {
                    $totalInRange++;
                    if (!isset($got[$other])) {
                        $missed++;
                    }
                }
            }
        }
        // 一个都不能漏：漏了就是"技能打不中明明在范围里的目标"
        T::eq($missed, 0, "半径 " . $radius . " 内 " . $totalInRange . " 对关系全部覆盖（零漏判）");
    }

    // 圈数随半径单调不减（否则"半径调大了反而看得更少"）
    $r1 = $g->ringsFor(50);
    $r2 = $g->ringsFor(150);
    $r3 = $g->ringsFor(450);
    T::ok($r1 <= $r2 && $r2 <= $r3, "ringsFor 随半径单调不减", $r1 . "/" . $r2 . "/" . $r3);
    T::ok($r1 === 1, "半径小于一格时仍是 3×3（k=1）", "k=" . $r1);
}

/**
 * 离开的人必须立刻不可见。
 *
 * 旧格不清理时，`neighbors()` 会把"已经走了的实体"继续返回——
 * 表现是"人已经跑开了，技能还打在他身上"。
 */
function caseLeaveInvisible(): void
{
    echo PHP_EOL . "== 离开/移动后立刻不可见 ==" . PHP_EOL;
    $g = new Grid(100, 10, 10, 999, 999);
    $g->insert(1, 10, 10);
    $g->insert(2, 20, 20);
    T::ok(isset($g->neighbors(1)[2]), "同格互相可见");

    $g->move(2, 900, 900);
    T::ok(!isset($g->neighbors(1)[2]), "移走后立刻不可见");
    T::ok(isset($g->neighbors(900 === 0 ? 1 : 1)[1]), "原格只剩自己（仍返回自己，调用方排除）");

    $g->remove(2);
    T::eq($g->entityCount(), 1, "移除后计数正确");
}

function caseScale(): void
{
    echo PHP_EOL . "== 规模：1 万实体仍与暴力法一致 ==" . PHP_EOL;
    $cellSize = 200;
    $cols = 50;
    $rows = 50;
    $g = new Grid($cellSize, $cols, $rows, 9999, 9999);
    mt_srand(4242);

    $pos = [];
    for ($uid = 1; $uid <= 10000; $uid++) {
        $x = mt_rand(0, 9999);
        $y = mt_rand(0, 9999);
        $g->insert($uid, $x, $y);
        $pos[$uid] = [$x, $y];
    }
    T::eq($g->entityCount(), 10000, "插入 1 万实体");

    // 抽样 60 个对拍（全量 O(N²) 太慢，抽样已能发现系统性错误）
    $bad = 0;
    $sample = 0;
    foreach ($pos as $eid => $p) {
        if ($sample >= 60) {
            break;
        }
        $sample++;
        $cx = intdiv($p[0], $cellSize);
        $cy = intdiv($p[1], $cellSize);
        $want = bruteNeighbors($pos, $cellSize, $cols, $rows, $cx, $cy);
        if (!sameSet($want, $g->neighbors($eid))) {
            $bad++;
        }
    }
    T::eq($bad, 0, "抽样 " . $sample . " 个实体的邻域一致");
}

function main(): void
{
    echo "== pkg/aoi 回归 ==" . PHP_EOL;
    caseBasic();
    caseBounds();
    caseMove();
    caseNeighborsMatchBrute();
    caseAroundRadius();
    caseLeaveInvisible();
    caseScale();

    echo PHP_EOL . "passed=" . T::$pass . " failed=" . T::$fail . PHP_EOL;
}
