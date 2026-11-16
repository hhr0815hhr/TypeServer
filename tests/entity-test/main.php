<?php

/**
 * pkg/entity 测试：坐标本身，以及「坐标与视野的同步」。
 *
 * 这里测的东西以前是塞在 room-test 里的（那时 Room 持有 Entity）。
 * 分开之后：Room 只管成员，坐标的推进、撞墙、AOI 同步全在这里，
 * 不装 entity 包的玩法完全看不到这些代码。
 */

use TSF\Aoi\Scene;
use TSF\Entity\Entity;
use TSF\Entity\EntitySet;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/** 1. 按 delta 推进 */
function caseAdvance(): void
{
    echo "== [1] 按 delta 推进 ==" . PHP_EOL;

    $e = new Entity(1, 1000, 500, 120, 0);   // 120 单位/秒
    $e->update(50);                           // 50ms → 6
    T::eq($e->x, 1006, "50ms 走 6");
    $e->update(50);
    T::eq($e->x, 1012, "再 50ms 到 12");

    // 可变 delta：卡顿 150ms 就推 150ms 的量
    $e2 = new Entity(2, 0, 0, 3000, 0);
    $e2->update(150);
    T::eq($e2->x, 450, "150ms × 3000/秒 = 450");

    // delta=0 不动
    $e3 = new Entity(3, 100, 100, 120, 0);
    $e3->update(0);
    T::eq($e3->x, 100, "delta=0 不动");
}

/** 2. 撞墙反弹 */
function caseBounce(): void
{
    echo "== [2] 撞墙反弹 ==" . PHP_EOL;

    $e = new Entity(1, Entity::MAX - 10, 500, 1000, 0);
    $e->update(100);   // 走 100 → 越界
    T::eq($e->x, Entity::MAX, "夹到边界");
    T::ok($e->vx < 0, "速度反向", "vx=" . $e->vx);

    $left = new Entity(2, 10, 500, -1000, 0);
    $left->update(100);
    T::eq($left->x, 0, "左边界夹到 0");
    T::ok($left->vx > 0, "左墙也反向", "vx=" . $left->vx);
}

/** 3. 集合的增删查 */
function caseSetBasic(): void
{
    echo "== [3] EntitySet 增删查 ==" . PHP_EOL;

    $ents = new EntitySet();
    $a = $ents->spawn(1, 100, 200, 50, 0);
    T::ok($a instanceof Entity, "spawn 返回实体");
    T::eq($ents->count(), 1, "计数 1");
    T::ok($ents->has(1), "has(1)");
    T::ok(!$ents->has(2), "has(2) 为 false");

    $got = $ents->get(1);
    T::ok($got !== null, "get(1) 拿得到");
    if ($got !== null) {
        T::eq($got->x, 100, "坐标正确");
    }
    T::ok($ents->get(9) === null, "get 不存在返回 null");

    T::ok($ents->remove(1), "remove 成功");
    T::ok(!$ents->remove(1), "重复 remove 返回 false");
    T::eq($ents->count(), 0, "计数归零");

    // 同一 uid 重复加入：spawn 会覆盖（后写胜），add 会拒绝
    $ents->spawn(5, 0, 0);
    T::ok(!$ents->add(new Entity(5, 1, 1)), "add 已存在的 uid 返回 false");
}

/** 4. 位置变化的通知语义——这是 EntitySet 存在的理由 */
function caseNotify(): void
{
    echo "== [4] 位置变化通知 ==" . PHP_EOL;

    $calls = 0;
    $lastX = 0;
    $cb = function (int $uid, int $x, int $y) use (&$calls, &$lastX): void {
        $calls++;
        $lastX = $x;
    };

    $ents = new EntitySet($cb);

    // spawn 立即通知一次（让视野索引把它收进去）
    $ents->spawn(1, 100, 100, 0, 0);
    T::eq($calls, 1, "spawn 触发一次通知");

    // 速度为 0：tick 后位置没变，不该再通知
    $ents->tick(50);
    T::eq($calls, 1, "没动就不通知");

    // 给速度，再 tick：动了就通知
    $ents->setVelocity(1, 1000, 0);
    T::eq($calls, 1, "setVelocity 本身不通知（位置没变）");
    $ents->tick(50);
    T::eq($calls, 2, "动起来之后通知");
    T::eq($lastX, 150, "通知里带新坐标");

    // moveTo 手动挪：立即通知
    $ents->moveTo(1, 900, 900);
    T::eq($calls, 3, "moveTo 触发通知");
    T::eq($lastX, 900, "通知新位置");

    // 不存在的 uid moveTo 返回 false，不通知
    T::ok(!$ents->moveTo(99, 0, 0), "moveTo 不存在的返回 false");
    T::eq($calls, 3, "失败不通知");
}

/** 5. 与 AOI 组合：坐标推进自动同步视野索引 */
function caseWithAoi(): void
{
    echo "== [5] 与 AOI 组合 ==" . PHP_EOL;

    $scene = new Scene(1000, 10, 10);
    $ents = new EntitySet(function (int $uid, int $x, int $y) use ($scene): void {
        // place 不存在就插、存在就挪格——EntitySet 不区分"首次"和"移动"
        $scene->place($uid, $x, $y);
    });

    $ents->spawn(1, 500, 500);      // 格子 0
    $ents->spawn(2, 5500, 500);     // 格子 5
    T::eq($scene->entityCount(), 2, "两个实体都在索引里");
    T::ok(!$scene->sees(1, 2), "相距 5000 互相看不见");

    // 1 号走过去：每帧 500 单位，走 10 帧到 5500
    $ents->setVelocity(1, 5000, 0);   // 5000 单位/秒 → 50ms 走 250
    for ($i = 0; $i < 22; $i++) {
        $ents->tick(50);
    }
    $one = $ents->get(1);
    T::ok($one !== null, "1 号还在");
    if ($one !== null) {
        T::ok($one->x > 5000, "已经走到 5000 以上", "x=" . $one->x);
    }
    T::ok($scene->sees(1, 2), "靠近后互相可见");

    // 移除：集合里没了，索引要由调用方自己摘（Scene::remove）
    $ents->remove(2);
    T::eq($ents->count(), 1, "集合里剩 1 个");
    T::eq($scene->entityCount(), 2, "索引里还有 2 个（remove 不自动同步）");
    $scene->remove(2);
    T::eq($scene->entityCount(), 1, "手动摘掉后索引同步");
}

/** 6. 性能：200 实体推进 */
function casePerf(): void
{
    echo "== [6] 性能 ==" . PHP_EOL;

    $n = 200;
    $ents = new EntitySet();
    for ($i = 1; $i <= $n; $i++) {
        $ents->spawn($i, $i * 10, 5000, 120, 0);
    }

    $frames = 200;
    $t0 = hrtime(true);
    for ($f = 0; $f < $frames; $f++) {
        $ents->tick(50);
    }
    $t1 = hrtime(true);
    $perFrame = ($t1 - $t0) / $frames;
    echo "  " . $n . " 实体推进: " . number_format($perFrame / 1000, 1) . " µs/帧"
        . "（" . number_format($perFrame / $n, 0) . " ns/实体）" . PHP_EOL;
    // $perFrame 是纳秒，阈值换算成纳秒比：5 ms = 5,000,000 ns
    T::ok($perFrame < 5000000, "单帧 < 5 ms", number_format($perFrame / 1000, 1) . " µs");

    // 带 AOI 同步的版本（换格开销更大，但大多数帧不换格）
    $scene = new Scene(1000, 10, 10);
    $ents2 = new EntitySet(function (int $uid, int $x, int $y) use ($scene): void {
        $scene->place($uid, $x, $y);
    });
    for ($i = 1; $i <= $n; $i++) {
        $ents2->spawn($i, ($i % 9) * 1000 + 500, intdiv($i, 9) * 1000 + 500, 100, 0);
    }
    $t2 = hrtime(true);
    for ($f = 0; $f < $frames; $f++) {
        $ents2->tick(50);
    }
    $t3 = hrtime(true);
    $per2 = ($t3 - $t2) / $frames;
    echo "  " . $n . " 实体 + AOI 同步: " . number_format($per2 / 1000, 1) . " µs/帧" . PHP_EOL;
    T::ok($per2 < 10000000, "带同步单帧 < 10 ms", number_format($per2 / 1000, 1) . " µs");
}

function main(): void
{
    caseAdvance();
    caseBounce();
    caseSetBasic();
    caseNotify();
    caseWithAoi();
    casePerf();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
