<?php

/**
 * ECS 值不值得：比较三种"同一份数据 + 同一个更新循环"的写法。
 *
 *   A 对象数组      —— 现在 Room 的写法（每个实体一个对象）
 *   B 组件并行数组  —— ECS 的 SoA 布局（x[] y[] vx[] vy[]，稠密下标）
 *   C 组件哈希表    —— 多数 PHP ECS 实现的写法（component[entityId] = value）
 *
 * 关键问题：ECS 的性能收益来自"连续内存 + 顺序访问"，这个前提在 AOT PHP 里成立吗？
 */

final class EntityObj
{
    public int $x;

    public int $y;

    public int $vx;

    public int $vy;

    public function __construct(int $x, int $y, int $vx, int $vy)
    {
        $this->x = $x;
        $this->y = $y;
        $this->vx = $vx;
        $this->vy = $vy;
    }

    public function update(int $deltaMs): void
    {
        $this->x += intdiv($this->vx * $deltaMs, 1000);
        $this->y += intdiv($this->vy * $deltaMs, 1000);
    }
}

function ms(float $ns): string
{
    return number_format($ns / 1e6, 1);
}

function main(): void
{
    $n = 1000;
    $frames = 200;
    $delta = 50;

    // ---- A 对象数组 ----
    $objs = [];
    for ($i = 0; $i < $n; $i++) {
        $objs[] = new EntityObj($i, $i * 2, 120, 80);
    }
    // 预热
    for ($f = 0; $f < 20; $f++) {
        foreach ($objs as $e) {
            $e->update($delta);
        }
    }
    $t0 = hrtime(true);
    for ($f = 0; $f < $frames; $f++) {
        foreach ($objs as $e) {
            $e->update($delta);
        }
    }
    $t1 = hrtime(true);
    $a = ($t1 - $t0) / $frames;
    echo "A 对象数组        " . ms($a) . " ms/帧  （" . round($a / $n) . " ns/实体）" . PHP_EOL;

    // ---- B 组件并行数组（SoA，稠密下标） ----
    $xs = [];
    $ys = [];
    $vxs = [];
    $vys = [];
    for ($i = 0; $i < $n; $i++) {
        $xs[] = $i;
        $ys[] = $i * 2;
        $vxs[] = 120;
        $vys[] = 80;
    }
    for ($f = 0; $f < 20; $f++) {
        for ($i = 0; $i < $n; $i++) {
            $xs[$i] += intdiv($vxs[$i] * $delta, 1000);
            $ys[$i] += intdiv($vys[$i] * $delta, 1000);
        }
    }
    $t0 = hrtime(true);
    for ($f = 0; $f < $frames; $f++) {
        for ($i = 0; $i < $n; $i++) {
            $xs[$i] += intdiv($vxs[$i] * $delta, 1000);
            $ys[$i] += intdiv($vys[$i] * $delta, 1000);
        }
    }
    $t1 = hrtime(true);
    $b = ($t1 - $t0) / $frames;
    echo "B 组件并行数组    " . ms($b) . " ms/帧  （" . round($b / $n) . " ns/实体）" . PHP_EOL;

    // ---- C 组件哈希表（entityId => 值，模拟常见 ECS 实现） ----
    $hx = [];
    $hy = [];
    $hvx = [];
    $hvy = [];
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $uid = 100000 + $i * 7; // 故意不连续，模拟真实实体 id
        $ids[] = $uid;
        $hx[$uid] = $i;
        $hy[$uid] = $i * 2;
        $hvx[$uid] = 120;
        $hvy[$uid] = 80;
    }
    for ($f = 0; $f < 20; $f++) {
        foreach ($ids as $eid) {
            $hx[$eid] += intdiv($hvx[$eid] * $delta, 1000);
            $hy[$eid] += intdiv($hvy[$eid] * $delta, 1000);
        }
    }
    $t0 = hrtime(true);
    for ($f = 0; $f < $frames; $f++) {
        foreach ($ids as $eid) {
            $hx[$eid] += intdiv($hvx[$eid] * $delta, 1000);
            $hy[$eid] += intdiv($hvy[$eid] * $delta, 1000);
        }
    }
    $t1 = hrtime(true);
    $c = ($t1 - $t0) / $frames;
    echo "C 组件哈希表      " . ms($c) . " ms/帧  （" . round($c / $n) . " ns/实体）" . PHP_EOL;

    echo PHP_EOL;
    echo "B/A = " . round($b / $a, 2) . "   C/A = " . round($c / $a, 2) . PHP_EOL;

    // ---- 增删实体的代价：ECS 的稀疏集 vs 对象数组 ----
    $add = 20000;

    $t0 = hrtime(true);
    for ($i = 0; $i < $add; $i++) {
        $objs[] = new EntityObj($i, $i, 1, 1);
    }
    $t1 = hrtime(true);
    $addObj = ($t1 - $t0) / $add;

    $t0 = hrtime(true);
    for ($i = 0; $i < $add; $i++) {
        $xs[] = $i;
        $ys[] = $i;
        $vxs[] = 1;
        $vys[] = 1;
    }
    $t1 = hrtime(true);
    $addSoa = ($t1 - $t0) / $add;

    echo "新增实体：对象 " . round($addObj) . " ns/个，SoA " . round($addSoa) . " ns/个" . PHP_EOL;
}
