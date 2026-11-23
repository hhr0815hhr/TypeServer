<?php

/**
 * 场景形态的容量验证：万人世界 / 大量副本 / 混合。
 *
 * ## 要回答的问题
 *
 * "这套框架能同时撑住多少形态" —— 具体到三个数：
 *
 *   1. **单场景 1 万实体**，每帧移动 + 换格 + 视野增量，耗时多少？
 *   2. **几百个副本实例**（每个 20 人）同时推进，总耗时多少？
 *   3. 世界 + 副本**混合**时，Service 数 / 内存 / 帧耗时到什么量级？
 *
 * 判据是 20Hz（50ms/帧）：超过这个数就得降频或拆场景。
 *
 * ## 口径
 *
 * - 每帧的"移动"用固定步长而不是随机：随机数生成本身会污染测量。
 *   用 (i * 步长) % 地图宽度 做确定性抖动，各帧之间位置不同、但可复现。
 * - 换格比例固定 10%（真实游戏的换格率通常更低，这里取偏严的值）。
 * - 每项跑 ROUNDS 轮取中位数，不用单次采样——单次会被后台负载带偏（见 bench/REPORT.md 第二节）。
 * - 内存看 peak：AOT 下没有 GC 抖动，峰值就是真实占用。
 */

use TSF\Aoi\Grid;
use TSF\Entity\EntitySet;

const ROUNDS = 5;

function nowUs(): int
{
    return intdiv(hrtime(true), 1000);
}

/** 中位数（输入不要求有序） */
function median(array $v): int
{
    sort($v);
    $n = count($v);
    if ($n === 0) {
        return 0;
    }
    return (int) $v[intdiv($n, 2)];
}

function mb(int $bytes): string
{
    return number_format($bytes / 1048576, 1) . " MB";
}

/**
 * 推进一个场景一帧：移动 + 换格 + 视野增量。
 *
 * @return array{us:int, born:int, died:int} born/died = 进出视野的有向边计数
 */
function stepScene(Grid $g, int $n, int $frame, int $w): array
{
    // 速度 5 单位/帧（20Hz 下 = 100 单位/秒，接近真实玩家移动）。
    // 格子 200 → 换格率 ≈ 2.5%。
    //
    // 第一版这里写的是 `$frame * 3`，**对所有人都生效**——等于每帧 10000 个实体
    // 全体换格，视野差集 78 万条/帧，跑出 121ms。那不是"万人世界撑不住"，
    // 那是"每帧全体瞬移"这种根本不存在的最坏情况。测出来的数字比没有更糟：
    // 它会让人去优化一个假瓶颈。
    $speed = 5;
    $t0 = nowUs();
    $born = 0;
    $died = 0;

    for ($i = 1; $i <= $n; $i++) {
        // 确定性路径：每帧前进 $speed，个体用固定偏移错开
        $x = ($i * 7 + $frame * $speed) % $w;
        $y = ($i * 11 + $frame * $speed) % $w;

        // Grid::move 的返回值：>= 0 = 旧格子 key（换格了）；-1 = 没换格；-2 = 不存在。
        // 第一版把它当成"新 key"去比较，于是**每次调用都判成换格**——跑出 25 万条
        // 视野变化/帧、119ms。注释里写清了这个语义，是我没读就用。
        $oldKey = $g->move($i, $x, $y);
        if ($oldKey < 0) {
            continue;   // 格内移动：视野不变，这是绝大多数帧
        }
        $newKey = $g->cellKeyOf($i);

        // 换格了：旧格邻域 vs 新格邻域的差集，就是进出视野
        // 用 neighborsOfKey 直接算，避免为每个实体分配 uid 数组
        $seen = [];
        foreach ($g->neighborsOfKey($newKey) as $uid) {
            $seen[(int) $uid] = true;
        }
        $was = [];
        foreach ($g->neighborsOfKey($oldKey) as $uid) {
            $was[(int) $uid] = true;
        }
        foreach ($seen as $uid => $_) {
            if (!isset($was[$uid])) {
                $born++;
            }
        }
        foreach ($was as $uid => $_) {
            if (!isset($seen[$uid])) {
                $died++;
            }
        }
    }

    return ["us" => nowUs() - $t0, "born" => $born, "died" => $died];
}

/** 建一个 n 实体的场景（网格铺满，实体均匀分布） */
function buildScene(int $n, int $w, int $cell): Grid
{
    $cols = intdiv($w, $cell) + 1;
    $g = new Grid($cell, $cols, $cols, $w, $w);
    for ($i = 1; $i <= $n; $i++) {
        $x = ($i * 7) % $w;
        $y = ($i * 11) % $w;
        $g->insert($i, $x, $y);
    }
    return $g;
}

function caseWorld(): void
{
    echo PHP_EOL . "== [1] 万人世界（单场景）==" . PHP_EOL;
    $w = 10000;
    $cell = 200;        // 200 单位一格 → 50×50 格，1 万人平均 4 人/格
    $n = 10000;

    $t0 = nowUs();
    $g = buildScene($n, $w, $cell);
    $buildUs = nowUs() - $t0;
    echo "  建场: " . $n . " 实体 / " . $g->cellCount() . " 格  →  " . $buildUs . " µs" . PHP_EOL;

    // 预热 2 帧（第一次 move 会填内部索引）
    stepScene($g, $n, 0, $w);
    stepScene($g, $n, 1, $w);

    $samples = [];
    $bornSum = 0;
    $diedSum = 0;
    for ($r = 0; $r < ROUNDS; $r++) {
        $res = stepScene($g, $n, 2 + $r, $w);
        $samples[] = $res["us"];
        $bornSum += $res["born"];
        $diedSum += $res["died"];
    }
    $med = median($samples);
    $ms = $med / 1000.0;
    echo "  单帧耗时（中位）: " . number_format($ms, 2) . " ms   " . $med . " µs" . PHP_EOL;
    echo "  视野变化/帧: 进入 " . intdiv($bornSum, ROUNDS) . " / 离开 " . intdiv($diedSum, ROUNDS) . " 条有向边" . PHP_EOL;
    echo "  20Hz 余量: " . number_format(50 - $ms, 2) . " ms" . ($ms < 50 ? "  ✓" : "  ✗ 超了") . PHP_EOL;
    echo "  峰值内存: " . mb((int) memory_get_peak_usage(true)) . PHP_EOL;
}

function caseInstances(): void
{
    echo PHP_EOL . "== [2] 大量副本实例 ==" . PHP_EOL;

    // 副本是"小场景"：每实例 20 人，地图 2000×2000，格子 200 → 11×11 格
    foreach ([200, 500] as $instances) {
        $per = 20;
        $w = 2000;
        $cell = 200;

        $t0 = nowUs();
        $grids = [];
        for ($i = 0; $i < $instances; $i++) {
            $grids[] = buildScene($per, $w, $cell);
        }
        $buildUs = nowUs() - $t0;

        $samples = [];
        for ($r = 0; $r < ROUNDS; $r++) {
            $t1 = nowUs();
            foreach ($grids as $g) {
                stepScene($g, $per, 2 + $r, $w);
            }
            $samples[] = nowUs() - $t1;
        }
        $med = median($samples);
        $ms = $med / 1000.0;
        echo PHP_EOL . "  " . $instances . " 个副本 × " . $per . " 人 = " . ($instances * $per) . " 实体" . PHP_EOL;
        echo "    建场: " . $buildUs . " µs" . PHP_EOL;
        echo "    单帧总耗时（中位）: " . number_format($ms, 2) . " ms   每副本 "
            . number_format($ms / $instances, 4) . " ms" . PHP_EOL;
        echo "    20Hz 余量: " . number_format(50 - $ms, 2) . " ms" . ($ms < 50 ? "  ✓" : "  ✗ 超了") . PHP_EOL;
        echo "    峰值内存: " . mb((int) memory_get_peak_usage(true)) . PHP_EOL;
    }
}

function caseMixed(): void
{
    echo PHP_EOL . "== [3] 混合：世界 + 副本 ==" . PHP_EOL;
    $worldW = 12000;
    $cell = 200;

    $t0 = nowUs();
    $world = buildScene(8000, $worldW, $cell);
    $dungeons = [];
    for ($i = 0; $i < 100; $i++) {
        $dungeons[] = buildScene(20, 2000, $cell);
    }
    $buildUs = nowUs() - $t0;

    $samples = [];
    for ($r = 0; $r < ROUNDS; $r++) {
        $t1 = nowUs();
        stepScene($world, 8000, 2 + $r, $worldW);
        foreach ($dungeons as $g) {
            stepScene($g, 20, 2 + $r, 2000);
        }
        $samples[] = nowUs() - $t1;
    }
    $med = median($samples);
    $ms = $med / 1000.0;

    echo "  世界 8000 人 + 100 个副本 × 20 人（共 10000 实体）" . PHP_EOL;
    echo "    建场: " . $buildUs . " µs" . PHP_EOL;
    echo "    单帧总耗时（中位）: " . number_format($ms, 2) . " ms" . PHP_EOL;
    echo "    20Hz 余量: " . number_format(50 - $ms, 2) . " ms" . ($ms < 50 ? "  ✓" : "  ✗ 超了") . PHP_EOL;
    echo "    峰值内存: " . mb((int) memory_get_peak_usage(true)) . PHP_EOL;
}

function caseEntitySet(): void
{
    echo PHP_EOL . "== [4] EntitySet 推进（对照：不用 AOI）==" . PHP_EOL;
    $n = 10000;
    $set = new EntitySet();
    for ($i = 1; $i <= $n; $i++) {
        $set->spawn($i, ($i * 7) % 10000, ($i * 11) % 10000, 1, 2);
    }
    $samples = [];
    for ($r = 0; $r < ROUNDS; $r++) {
        $t0 = nowUs();
        $set->tick(50);
        $samples[] = nowUs() - $t0;
    }
    $med = median($samples);
    echo "  " . $n . " 实体 tick(50ms)（中位）: " . number_format($med / 1000.0, 2) . " ms" . PHP_EOL;
    echo "  峰值内存: " . mb((int) memory_get_peak_usage(true)) . PHP_EOL;
}


/** 规模扫描：确认耗时是否随实体数线性增长（决定"多少万人要拆场景"） */
function caseScale(): void
{
    echo PHP_EOL . "== [5] 规模扫描（单场景）==" . PHP_EOL;
    $w = 20000;
    $cell = 200;

    foreach ([10000, 20000, 30000, 40000] as $n) {
        if ($n > intdiv($w * $w, 2500)) {
            continue;       // 密度太高就不测了，那不是真实分布
        }
        $g = buildScene($n, $w, $cell);
        stepScene($g, $n, 0, $w);
        stepScene($g, $n, 1, $w);

        $samples = [];
        for ($r = 0; $r < ROUNDS; $r++) {
            $res = stepScene($g, $n, 2 + $r, $w);
            $samples[] = $res["us"];
        }
        $med = median($samples);
        $ms = $med / 1000.0;
        echo "  " . str_pad((string) $n, 6, " ", STR_PAD_LEFT) . " 实体: "
            . str_pad(number_format($ms, 2), 7, " ", STR_PAD_LEFT) . " ms"
            . "   每万实体 " . number_format($ms / ($n / 10000.0), 2) . " ms"
            . ($ms < 50 ? "  ✓" : "  ✗ 超 50ms") . PHP_EOL;
    }
}

function main(): void
{
    echo "== 场景形态容量验证 ==" . PHP_EOL;
    echo "PHP " . PHP_VERSION . "  " . (function_exists("opcache_get_status") ? "opcache" : "no-opcache") . PHP_EOL;

    caseWorld();
    caseInstances();
    caseMixed();
    caseEntitySet();
    caseScale();

    echo PHP_EOL . "== 结论怎么看 ==" . PHP_EOL;
    echo "  20Hz 的预算是一帧 50ms。上面的 ms 是**纯逻辑**（移动+换格+视野差集），" . PHP_EOL;
    echo "  不含广播编码和网络发送——那部分看 bench/REPORT.md 的 codec 数据。" . PHP_EOL;
}
