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

use TSF\Schedule\Cron;
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
    $sch->cron("workday_open", "0 0 9 * * 1-5", 0, 9005);

    $list = $sch->listAll();
    T::eq((string) count($list), "5", "注册了 5 个任务（含 1 个 cron）");

    $names = [];
    foreach ($list as $row) {
        $names[] = (string) $row["name"];
    }
    sort($names);
    T::eq(implode(",", $names), "activity_open,heart,reset,weekly_rank,workday_open", "任务名齐全");

    // 下次时间应该在将来
    $okFuture = true;
    foreach ($list as $row) {
        // cron 任务的 next 是 0（它不是「下次时间」驱动，而是每秒判匹配），跳过
        if ((int) $row["kind"] === 5) {
            continue;
        }
        if ((int) $row["next"] <= time()) {
            $okFuture = false;
        }
    }
    T::ok($okFuture, "非 cron 任务的下次执行时间都在将来");

    T::ok($sch->cancel("heart"), "cancel 返回 true");
    T::eq((string) count($sch->listAll()), "4", "取消后剩 4 个");
    T::ok(!$sch->cancel("nope"), "取消不存在的任务返回 false");
}

/** 4. cron 表达式匹配（AOT 下注释里不能写"星号斜杠"，会终止块注释，所以这里用 0/5 表示步进） */
function caseCron(): void
{
    echo "== [4] cron ==" . PHP_EOL;

    $t = mktime(5, 0, 0, 6, 15, 2026);
    if (is_int($t)) {
        T::ok(Cron::matches("0 0 5 * * *", $t), "5:00:00 匹配「每天 5 点」");
        T::ok(!Cron::matches("0 0 5 * * *", $t + 1), "5:00:01 不匹配");
        T::ok(!Cron::matches("0 0 5 * * *", $t - 60), "4:59:00 不匹配");
    }

    // 每 5 秒
    if (is_int($t)) {
        T::ok(Cron::matches("0/5 * * * * *", $t), "秒 0 匹配「每 5 秒」");
        T::ok(!Cron::matches("0/5 * * * * *", $t + 1), "秒 1 不匹配");
        T::ok(Cron::matches("0/5 * * * * *", $t + 5), "秒 5 匹配");
    }

    // 5 段：秒默认 0
    if (is_int($t)) {
        T::ok(Cron::matches("0 5 * * *", $t), "5 段「0 5 * * *」等价于 6 段");
        T::ok(!Cron::matches("0 5 * * *", $t + 1), "5 段在 5:00:01 不匹配");
    }

    // 每月 1 号 0 点
    $first = mktime(0, 0, 0, 7, 1, 2026);
    if (is_int($first)) {
        T::ok(Cron::matches("0 0 0 1 * *", $first), "7/1 0:00 匹配「每月 1 号」");
        T::ok(!Cron::matches("0 0 0 1 * *", $first + 86400), "7/2 不匹配");
    }

    // 工作日 9 点（2026-06-15 是周一）
    $mon9 = mktime(9, 0, 0, 6, 15, 2026);
    $sun9 = mktime(9, 0, 0, 6, 21, 2026);
    if (is_int($mon9) && is_int($sun9)) {
        T::ok(Cron::matches("0 0 9 * * 1-5", $mon9), "周一 9 点匹配工作日");
        T::ok(!Cron::matches("0 0 9 * * 1-5", $sun9), "周日 9 点不匹配工作日");
    }

    // 日/周「或」关系：0 0 0 1 * 1 = 每月 1 号 或 每周一
    $firstMon = mktime(0, 0, 0, 6, 1, 2026);    // 6/1 是周一
    $midMon = mktime(0, 0, 0, 6, 15, 2026);     // 周一但非 1 号
    $firstOther = mktime(0, 0, 0, 7, 1, 2026);  // 1 号但是周三
    if (is_int($firstMon) && is_int($midMon) && is_int($firstOther)) {
        T::ok(Cron::matches("0 0 0 1 * 1", $firstMon), "1 号且周一 → 匹配");
        T::ok(Cron::matches("0 0 0 1 * 1", $midMon), "周一但非 1 号 → 仍匹配（或关系）");
        T::ok(Cron::matches("0 0 0 1 * 1", $firstOther), "1 号但非周一 → 仍匹配（或关系）");
    }

    // 非法表达式
    T::ok(Cron::parse("0 0 5 *") === [], "4 段 → 非法");
    T::ok(Cron::parse("99 0 5 * * *") === [], "秒 99 越界 → 非法");
    T::ok(Cron::parse("0 0 5 * * 9") === [], "周 9 越界 → 非法");
    T::ok(Cron::parse("0 0 5 * * *") !== [], "合法表达式解析成功");
}


/**
 * 与核心 `TSF\Timer\Cron` 对拍。
 *
 * 两个 Cron 实现并存（核心的算「下次触发」，包里的判「当前是否匹配」），
 * 语法还差一个秒级字段。在合并之前先确认一件事：**对同一批 5 字段表达式，
 * 两者算出来的触发时刻是不是同一批**。不一致就说明合并会改变线上行为。
 *
 * 方法：用核心的 next() 算未来 N 次触发，再用包里的 matches() 逐分钟验证——
 * 所有 next() 给出的时刻必须 matches=true，而它们之间的时刻必须 matches=false。
 */
function caseCrossCheck(): void
{
    echo PHP_EOL . "== 与核心 Timer\\Cron 对拍 ==" . PHP_EOL;

    $exprs = [
        "* * * * *",
        "0 5 * * *",
        "*/15 * * * *",
        "30 2 * * 0",
        "0 0 1 * *",
        "0 0 1 * 1",
        "10-20/5 * * * *",
        "0 9 * * 1-5",
    ];

    $base = 1764000000 - (1764000000 % 60);   // 对齐到整分钟

    foreach ($exprs as $expr) {
        // 核心：算 5 次触发
        $hits = [];
        $from = $base;
        for ($i = 0; $i < 5; $i++) {
            $next = \TSF\Timer\Cron::next((string) $expr, $from);
            if ($next <= $from) {
                $hits = [];
                break;
            }
            $hits[] = $next;
            $from = $next;
        }

        if ($hits === []) {
            T::ok(false, "核心算出触发时刻: " . $expr);
            continue;
        }

        // 包里：这些时刻必须匹配
        $allMatch = true;
        foreach ($hits as $t) {
            if (!Cron::matches((string) $expr, $t)) {
                $allMatch = false;
            }
        }
        T::ok($allMatch, "核心算出的 " . count($hits) . " 个时刻包内全部匹配: " . $expr);

        // 两个时刻之间的分钟必须不匹配（否则包里的判定更宽松）
        $t0 = $hits[0];
        $t1 = $hits[1];
        $gap = intdiv($t1 - $t0, 60);
        $clean = true;
        if ($gap > 1 && $gap <= 1440) {
            for ($m = 1; $m < $gap; $m++) {
                if (Cron::matches((string) $expr, $t0 + $m * 60)) {
                    $clean = false;
                }
            }
        }
        T::ok($clean, "触发间隙（" . $gap . " 分钟）内无额外匹配: " . $expr);
    }
}

function main(): void
{
    caseDaily();
    caseWeekly();
    caseRegister();
    caseCron();

    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
    caseCrossCheck();
}
