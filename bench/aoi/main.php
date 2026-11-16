<?php

use TSF\Aoi\Grid;
use TSF\Aoi\Scene;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $name, string $extra = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  PASS  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL  " . $name . ($extra !== "" ? "  (" . $extra . ")" : "") . PHP_EOL;
        }
    }
}

/**
 * 确定性伪随机（LCG）。不用 rand()：不可重复会让测量无法复现，而且 rand 本身有开销。
 * 用对象存状态而不是 `function f(int &$seed)`：引用参数在 AOT 下有额外风险。
 */
final class Rand
{
    public int $s;

    public function __construct(int $seed)
    {
        $this->s = $seed;
    }

    public function next(int $mod): int
    {
        $this->s = ($this->s * 1103515245 + 12345) & 0x7FFFFFFF;
        return $this->s % $mod;
    }
}

/** 把 1..n 的视野大小加起来（有向边总数） */
function totalView(Scene $s, int $n): int
{
    $t = 0;
    for ($i = 1; $i <= $n; $i++) {
        $t += count($s->viewOf($i));
    }
    return $t;
}

// ---------------- 功能 ----------------

function caseSameCell(): void
{
    echo "[1] 同格互见" . PHP_EOL;
    $s = new Scene(1000, 10, 10);
    $s->add(1, 100, 100);
    $s->add(2, 200, 200);
    $s->add(3, 300, 300);

    T::ok($s->sees(1, 2) && $s->sees(2, 1), "1↔2 互见");
    T::ok($s->sees(1, 3), "1↔3 互见");
    T::ok(count($s->viewOf(1)) === 2, "1 的视野有 2 人", "n=" . count($s->viewOf(1)));
    T::ok(totalView($s, 3) === 6, "3 人 6 条有向边", "edges=" . totalView($s, 3));
    T::ok($s->enters() === 6, "enter 计数 6", "enters=" . $s->enters());
}

function caseAdjacent(): void
{
    echo PHP_EOL . "[2] 邻格可见、隔格不可见" . PHP_EOL;
    // cellSize=1000：[0,999]=格0，[1000,1999]=格1，[2000,2999]=格2
    $s = new Scene(1000, 10, 10);
    $s->add(1, 100, 100);    // 格(0,0)
    $s->add(2, 1100, 100);   // 格(1,0)，与格(0,0) 相邻
    $s->add(3, 2100, 100);   // 格(2,0)，与格(0,0) 隔一格

    T::ok($s->sees(1, 2), "相邻格互见");
    T::ok(!$s->sees(1, 3), "隔一格不可见");
    T::ok($s->sees(2, 3), "2 与 3 相邻，互见");

    // 对角相邻也算
    $s->add(4, 1100, 1100);
    T::ok($s->sees(1, 4), "对角格互见");
}

function caseMoveAcrossCells(): void
{
    echo PHP_EOL . "[3] 跨格移动：视野重算 + 对称性" . PHP_EOL;
    $s = new Scene(1000, 10, 10);
    $s->add(1, 100, 100);      // 格(0,0)
    $s->add(2, 1100, 100);     // 格(1,0)
    $s->add(3, 5000, 5000);    // 远处，永不可见

    T::ok(totalView($s, 3) === 2, "初始 2 条有向边", "edges=" . totalView($s, 3));

    $changed = $s->move(1, 1200, 100);
    T::ok($changed === 1, "返回 1 表示换格");
    T::ok($s->sees(1, 2) && $s->sees(2, 1), "换格后仍然互见");
    T::ok(totalView($s, 3) === 2, "边数不变（本来就能互相看到）");

    $s->move(1, 9000, 9000);
    T::ok(!$s->sees(1, 2), "远离后不可见");
    T::ok(!$s->sees(2, 1), "反向也不可见（对称性）");
    T::ok(totalView($s, 3) === 0, "边数归零");
    T::ok($s->leaves() === 2, "leave 计数 2（一对互见 = 2 条有向边）", "leaves=" . $s->leaves());
    T::ok($s->crossings() === 2, "换格 2 次", "crossings=" . $s->crossings());
}

function caseInCellNoop(): void
{
    echo PHP_EOL . "[4] 格内移动不改视野、不计数" . PHP_EOL;
    $s = new Scene(1000, 10, 10);
    $s->add(1, 100, 100);
    $s->add(2, 200, 100);
    $e0 = $s->enters();

    $allZero = true;
    for ($i = 0; $i < 10; $i++) {
        if ($s->move(1, 100 + $i * 50, 100) !== 0) {
            $allZero = false;
        }
    }
    T::ok($allZero, "10 次格内移动都返回 0");
    T::ok($s->crossings() === 0, "换格次数为 0");
    T::ok(count($s->viewOf(1)) === 1, "视野不变");
    T::ok($s->enters() === $e0, "enter 计数不变", "enters=" . $s->enters());
}

function caseRemove(): void
{
    echo PHP_EOL . "[5] 离开场景" . PHP_EOL;
    $s = new Scene(1000, 10, 10);
    for ($i = 1; $i <= 5; $i++) {
        $s->add($i, 100 * $i, 100);
    }
    T::ok(totalView($s, 5) === 20, "5 人同格 20 条边", "edges=" . totalView($s, 5));

    $s->remove(1);
    T::ok($s->entityCount() === 4, "实体数减少", "n=" . $s->entityCount());
    T::ok(totalView($s, 5) === 12, "剩 4 人 12 条边", "edges=" . totalView($s, 5));
    T::ok(!$s->sees(2, 1), "看不到已离开的人");
    T::ok($s->leaves() === 8, "leave 计数 8（4 对）", "leaves=" . $s->leaves());

    T::ok(!$s->grid()->remove(999), "移除不存在的实体返回 false");
}

function caseClamp(): void
{
    echo PHP_EOL . "[6] 越界坐标夹到边界" . PHP_EOL;
    $g = new Grid(1000, 10, 10, 10000, 10000);
    T::ok($g->keyOf(-500, -500) === $g->keyOf(0, 0), "负坐标夹到 (0,0)");
    T::ok($g->keyOf(999999, 999999) === $g->keyOf(9999, 9999), "超大坐标夹到末格");

    $s = new Scene(1000, 10, 10);
    $s->add(1, -100, -100);
    $s->add(2, 50, 50);
    T::ok($s->sees(1, 2), "越界加入后仍落在有效格子里");
}

// ---------------- 性能 ----------------

function perf(): void
{
    $n = 1000;
    $cellSize = 1000;
    $cols = 10;
    $size = 10000;

    echo "== AOI 性能（" . $n . " 实体，" . $cellSize . " 单位格子，" . $cols . "×" . $cols . " 格）==" . PHP_EOL;

    $r = new Rand(20261113);
    $scene = new Scene($cellSize, $cols, $cols, $size, $size);
    $px = [];
    $py = [];
    for ($uid = 1; $uid <= $n; $uid++) {
        $px[$uid] = $r->next($size);
        $py[$uid] = $r->next($size);
        $scene->add($uid, $px[$uid], $py[$uid]);
    }

    $sample = 0;
    for ($uid = 1; $uid <= 100; $uid++) {
        $sample += count($scene->viewOf($uid));
    }
    echo "  占用格子        " . $scene->grid()->cellCount() . " 个（平均 "
        . number_format($n / $scene->grid()->cellCount(), 1) . " 人/格）" . PHP_EOL;
    echo "  平均视野        抽样 100 人，平均 " . number_format($sample / 100, 1) . " 个邻居" . PHP_EOL;

    // 格内移动（热路径）
    $iter = 200000;
    $t0 = hrtime(true);
    $sink = 0;
    for ($i = 0; $i < $iter; $i++) {
        $uid = ($i % $n) + 1;
        $sink += $scene->move($uid, $px[$uid], $py[$uid]);
    }
    $t1 = hrtime(true);
    $inCell = ($t1 - $t0) / $iter;
    echo "  格内移动        " . number_format($inCell, 0) . " ns/次" . PHP_EOL;
    if ($sink < 0) {
        echo "";
    }

    // 跨格移动（含视野重算）
    $cross = 20000;
    $t0 = hrtime(true);
    for ($i = 0; $i < $cross; $i++) {
        $scene->move(1, 100, 100);
        $scene->move(1, 5100, 5100);
    }
    $t1 = hrtime(true);
    $perCross = ($t1 - $t0) / ($cross * 2);
    echo "  跨格移动        " . number_format($perCross, 0) . " ns/次（含重算视野）" . PHP_EOL;

    // 邻居查询
    $q = 20000;
    $t0 = hrtime(true);
    $cnt = 0;
    for ($i = 0; $i < $q; $i++) {
        $cnt += count($scene->grid()->neighbors(($i % $n) + 1));
    }
    $t1 = hrtime(true);
    echo "  邻居查询        " . number_format(($t1 - $t0) / $q, 0) . " ns/次（平均 "
        . number_format($cnt / $q, 1) . " 个）" . PHP_EOL;

    // 朴素两两比较
    $t0 = hrtime(true);
    $hit = 0;
    for ($i = 1; $i <= 20; $i++) {
        $x = $px[$i];
        $y = $py[$i];
        foreach ($px as $oid => $ox) {
            $dx = $ox - $x;
            $dy = $py[$oid] - $y;
            if ($dx * $dx + $dy * $dy <= 90000) {
                $hit++;
            }
        }
    }
    $t1 = hrtime(true);
    echo "  朴素全量比较    " . number_format(($t1 - $t0) / 20, 0) . " ns/次（扫描 " . $n . " 人）" . PHP_EOL;
    if ($hit < 0) {
        echo "";
    }

    // 整帧：1000 人全部移动一小步（±30 单位，约 6% 概率换格）
    $frames = 200;
    $durs = [];
    $r2 = new Rand(99);
    $curX = $px;
    $curY = $py;
    for ($f = 0; $f < $frames; $f++) {
        $t0 = hrtime(true);
        for ($uid = 1; $uid <= $n; $uid++) {
            $nx = $curX[$uid] + $r2->next(60) - 30;
            $ny = $curY[$uid] + $r2->next(60) - 30;
            $curX[$uid] = $nx;
            $curY[$uid] = $ny;
            $scene->move($uid, $nx, $ny);
        }
        $t1 = hrtime(true);
        $durs[] = $t1 - $t0;
    }
    sort($durs);
    $p50 = intdiv($durs[intdiv($frames, 2)], 1000);
    $p99 = intdiv($durs[intdiv($frames * 99, 100)], 1000);
    $max = intdiv($durs[$frames - 1], 1000);
    $perEntity = ($durs[intdiv($frames, 2)]) / $n;
    echo "  整帧 1000 人    p50=" . $p50 . " µs  p99=" . $p99 . " µs  max=" . $max . " µs" . PHP_EOL;
    echo "  折合单实体      " . number_format($perEntity, 0) . " ns" . PHP_EOL;
    echo "  单次更新达标    " . ($perEntity <= 50000 ? "是" : "否") . "（目标 ≤50µs/次）" . PHP_EOL;
}

function main(): void
{
    $argvv = $_SERVER["argv"] ?? [];
    $role = isset($argvv[1]) ? (string) $argvv[1] : "test";

    if ($role === "perf") {
        perf();
        return;
    }

    echo "== AOI 功能测试 ==" . PHP_EOL;
    caseSameCell();
    caseAdjacent();
    caseMoveAcrossCells();
    caseInCellNoop();
    caseRemove();
    caseClamp();
    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
