<?php

/**
 * pkg/schedule 的自测。
 *
 * 只测两件事：
 *   1. 时间计算 —— 这是"重启后不漂移"的根本，边界容易错（正好到点、跨周、跨天）
 *   2. 注册表 —— 注册/取消/列出
 *
 * 不测"真实到点投消息"：那要驱动 Scheduler 的定时器循环，受环境影响大，
 * 而且它复用框架已有的 interval 机制（那个有别的测试覆盖）。真正跑起来是
 * doudizhu 里的每日重置那类用法。
 */

use TSF\Schedule\Schedule;

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

    public static function eq(string $got, string $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/** 1. 每天：今天过了就顺延，正好到点也要顺延（不能原地循环） */
function caseDaily(): void
{
    echo "== [1] 每日 ==" . PHP_EOL;

    $noon = mktime(12, 0, 0, 6, 15, 2026);
    if (is_int($noon)) {
        T::eq(date("Y-m-d H:i", Schedule::nextDaily(5, 0, $noon)), "2026-06-16 05:00", "12:00 时 → 明天 5:00");
    }

    $early = mktime(3, 0, 0, 6, 15, 2026);
    if (is_int($early)) {
        T::eq(date("Y-m-d H:i", Schedule::nextDaily(5, 0, $early)), "2026-06-15 05:00", "3:00 时 → 今天 5:00");
    }

    $exact = mktime(5, 0, 0, 6, 15, 2026);
    if (is_int($exact)) {
        T::eq(date("Y-m-d H:i", Schedule::nextDaily(5, 0, $exact)), "2026-06-16 05:00", "正好 5:00 → 顺延到明天");
    }

    // 跨月：6/30 12:00 问 5:00 → 7/1 5:00
    $monthEnd = mktime(12, 0, 0, 6, 30, 2026);
    if (is_int($monthEnd)) {
        T::eq(date("Y-m-d H:i", Schedule::nextDaily(5, 0, $monthEnd)), "2026-07-01 05:00", "月末跨到次月");
    }
}

/** 2. 每周：2026-06-15 是周一 */
function caseWeekly(): void
{
    echo "== [2] 每周 ==" . PHP_EOL;

    $mon = mktime(10, 0, 0, 6, 15, 2026);
    if (is_int($mon)) {
        T::eq(date("Y-m-d H:i N", Schedule::nextWeekly(1, 0, 0, $mon)), "2026-06-22 00:00 1", "周一 10:00 → 下周一 0:00");
    }

    $tue = mktime(10, 0, 0, 6, 16, 2026);
    if (is_int($tue)) {
        T::eq(date("Y-m-d H:i N", Schedule::nextWeekly(1, 0, 0, $tue)), "2026-06-22 00:00 1", "周二 → 下周一 0:00");
    }

    $sun = mktime(10, 0, 0, 6, 21, 2026);
    if (is_int($sun)) {
        T::eq(date("Y-m-d H:i N", Schedule::nextWeekly(1, 0, 0, $sun)), "2026-06-22 00:00 1", "周日 → 明天（周一）0:00");
    }

    // 周三问"下周三"（同一 weekday 但时间已过）
    $wed = mktime(10, 0, 0, 6, 17, 2026);
    if (is_int($wed)) {
        T::eq(date("Y-m-d H:i N", Schedule::nextWeekly(3, 0, 0, $wed)), "2026-06-24 00:00 3", "周三 10:00 → 下周三 0:00");
    }
}

/** 3. 注册表：注册、列出、取消 */
function caseRegister(): void
{
    echo "== [3] 注册与列表 ==" . PHP_EOL;

    $sch = new Schedule();
    // target=0 表示"不投递"，这里只验证注册表本身
    $sch->daily("reset", 5, 0, 0, 9001);
    $sch->weekly("weekly_rank", 1, 0, 0, 0, 9002);
    $sch->repeat("heart", 30, 0, 9003);
    $sch->at("activity_open", time() + 3600, 0, 9004);

    $list = $sch->listAll();
    T::eq((string) count($list), "4", "注册了 4 个任务");

    $names = [];
    foreach ($list as $row) {
        $names[] = (string) $row["name"];
    }
    sort($names);
    T::eq(implode(",", $names), "activity_open,heart,reset,weekly_rank", "任务名齐全");

    // 下次时间应该在将来
    $okFuture = true;
    foreach ($list as $row) {
        if ((int) $row["next"] <= time()) {
            $okFuture = false;
        }
    }
    T::ok($okFuture, "所有任务的下次执行时间都在将来");

    T::ok($sch->cancel("heart"), "cancel 返回 true");
    T::eq((string) count($sch->listAll()), "3", "取消后剩 3 个");
    T::ok(!$sch->cancel("nope"), "取消不存在的任务返回 false");
}

function main(): void
{
    caseDaily();
    caseWeekly();
    caseRegister();

    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
}
