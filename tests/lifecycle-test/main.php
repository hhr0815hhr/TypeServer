<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 生命周期：批量销毁带定时器的服务后，时间轮里的死对象必须被清掉。
 *
 * ## 钉的是哪个 bug（2026-11-26）
 *
 * `Scheduler::tick()` 原来判断 `if ($this->timers->size() > 0)` 才调 `due()`，
 * 而 `size()` 只数**有效**定时器。服务批量销毁时 `cancelAllOf` 把 live 清空、
 * 把对象标记 cancelled 留在时间轮槽里等翻到——于是 `size()` 为 0，
 * `due()` 再也不被调用，游标永远不前进，死对象永远留着。
 *
 * 现象是"内存单调增长，而 `stats()["timers"]` 一直是 0"。触发条件是
 * **服务批量销毁且系统里没有其他活定时器**——一局结束、房间全部销毁，
 * 正是"一房一 Service"的日常。
 *
 * 实测（bench/lifecycle）：每轮 2000 个定时器，十轮涨 6 MB 不收敛；
 * 修好后第三轮起净增为 0。
 */

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

/** 进房时开一个 20ms 倒计时，出房就被 kill */
final class Timed extends Service
{
    public function onStart(): void
    {
        $this->timeout(20, 1);
    }

    public function onMessage(Message $m): void
    {
    }
}

function main(): void
{
    echo "== 生命周期：定时器死对象清理 ==" . PHP_EOL;

    // ---- 1. 基线：批量销毁后 size() 归零，但时间轮里还有东西 ----
    Scheduler::reset();
    $s = Scheduler::instance();

    $addrs = [];
    for ($i = 0; $i < 500; $i++) {
        $addrs[] = $s->spawnOf(function (): Service {
            return new Timed();
        });
    }
    $before = $s->stats();
    T::ok($before["timers"] === 500, "500 个服务各挂一个定时器", "timers=" . $before["timers"]);

    foreach ($addrs as $a) {
        $s->kill($a);
    }
    $afterKill = $s->stats();
    T::ok($afterKill["timers"] === 0, "销毁后有效定时器归零", "timers=" . $afterKill["timers"]);
    // 这一条是 bug 的现场：size() 为 0，但对象还压在轮里
    T::ok($afterKill["timer_wheel_items"] > 0, "销毁后时间轮里仍有待清理的对象",
        "wheelItems=" . $afterKill["timer_wheel_items"]);

    // ---- 2. 推进时间 + tick：必须清干净 ----
    // 修之前这一步完全无效，因为 tick 看到 size()==0 就跳过了 due()
    usleep(30000);
    $s->tick();
    $afterTick = $s->stats();
    T::ok($afterTick["timer_wheel_items"] === 0, "tick 之后时间轮清空",
        "wheelItems=" . $afterTick["timer_wheel_items"]);
    T::ok($afterTick["services"] === 0, "服务数为 0");
    T::ok($afterTick["timers"] === 0, "有效定时器为 0");

    // ---- 3. 反复建销毁：内存必须收敛 ----
    // 判据是"后两轮增量为 0"。第一轮会有一次性的哈希表扩容（实测约 500 KB），
    // 那之后必须完全平坦；单调上升就是泄漏
    $warm = [];
    for ($i = 0; $i < 500; $i++) {
        $warm[] = $s->spawnOf(function (): Service {
            return new Timed();
        });
    }
    foreach ($warm as $a) {
        $s->kill($a);
    }
    unset($warm);
    usleep(30000);
    $s->tick();

    $prev = memory_get_usage(false);
    $grows = [];
    for ($r = 0; $r < 5; $r++) {
        $batch = [];
        for ($i = 0; $i < 500; $i++) {
            $batch[] = $s->spawnOf(function (): Service {
                return new Timed();
            });
        }
        foreach ($batch as $a) {
            $s->kill($a);
        }
        unset($batch);
        usleep(30000);
        $s->tick();

        $now = memory_get_usage(false);
        $grows[] = $now - $prev;
        $prev = $now;
    }
    $lastA = 0;
    $lastB = 0;
    $n = count($grows);
    if ($n >= 2) {
        $lastA = $grows[$n - 2];
        $lastB = $grows[$n - 1];
    }
    T::ok($lastA === 0 && $lastB === 0, "后两轮内存增量为 0（收敛）",
        "最后两轮 " . $lastA . " / " . $lastB . " B");

    $st = $s->stats();
    T::ok($st["timer_wheel_items"] === 0, "收敛后时间轮为空", "wheelItems=" . $st["timer_wheel_items"]);

    echo PHP_EOL . "passed=" . T::$pass . " failed=" . T::$fail . PHP_EOL;
}
