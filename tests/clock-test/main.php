<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 时钟：cron 定时器必须按**墙钟**落点，而不是按单调时钟。
 *
 * ## 钉的是哪个 bug（2026-11-26）
 *
 * `Scheduler::every()` 原来这样算：
 *
 *     $now = self::nowMs();                            // hrtime 毫秒（起点 = 系统启动）
 *     $nextTs = Cron::next($cron, intdiv($now, 1000)); // 把 hrtime 秒当 Unix 时间戳
 *     $this->timers->add($now, $addr, $nextTs * 1000); // Unix 毫秒当单调毫秒
 *
 * `Cron` 内部用 `date()` / `mktime()`，按 Unix 纪元解析。把 hrtime 秒喂进去，
 * 相当于告诉它"现在是 1970-01-01 的某个时刻"——于是：
 *
 *   - "每 5 分钟" 这类**只按分钟**算的表达式碰巧是对的（只用到差值）
 *   - "每天 3 点" 这类**涉及日期**的完全错：实测返回 68400 秒（19 小时后），
 *     而它应该是"到下一个 3 点还有多久"
 *
 * 隐蔽之处在于简单用例能过、复杂用例错，而 `stats()["timers"]` 只说数量、
 * 看不出落点偏了。
 *
 * ## 怎么测落点
 *
 * 拿 `nextTimerDueAt()` 的单调毫秒、`Scheduler::nowMs()` 的当前单调毫秒、
 * 以及 `time()` 的墙钟，换算出"它会在墙钟的什么时刻触发"，再检查那个时刻的
 * 时/分/日字段。这样不依赖测试运行的时刻。
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

final class Idle extends Service
{
    public function onMessage(Message $m): void
    {
    }
}

/** 把"单调毫秒的到期时刻"换算成墙钟秒 */
function wallOf(int $dueMonoMs): int
{
    // 用毫秒墙钟对齐：拿 time() 的秒精度去换算会差最多 1 秒，
    // 那点误差足够让"3:00"显示成"2:59:59"
    $delayMs = $dueMonoMs - Scheduler::nowMs();
    $wallMs = (int) (microtime(true) * 1000.0) + $delayMs;
    // **取整到最近的秒，而不是向下截断。**
    // 落点正好压在整秒上，而换算带着 ±1 毫秒的抖动。向下截断时负的那一侧落进前一秒：
    // "03:00"变成"02:59:59"，断言红。
    // 2026-11-27 实测：给 checkCron 加毫秒偏移探针，偏移只有 0、1、999 三种取值
    // （999 = 差 1 毫秒到整点）。变异检查——把 +500 去掉、经由 run.sh 跑 25 次，红 4 次，
    // 红的断言正是"每天 03:00（落点 02:59:59）"和"*/5 落在整 5 分（落点 17:14:59）"；
    // 加回来之后同样方式跑 60 次，0 红。
    // （直接反复执行同一个产物 300 次都不红，必须经由 run.sh 才复现——原因没查，
    // 但红的那一侧是确定的。）
    // cron 的粒度是分钟，这里断言的是"整分整点"，秒级取整才是要测的行为。
    return intdiv($wallMs + 500, 1000);
}

function checkCron(string $expr, int $expHour, int $expMin, string $label): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $addr = $s->spawnOf(function (): Service {
        return new Idle();
    });
    $s->every($expr, $addr, 1);

    $due = $s->nextTimerDueAt();
    if ($due <= 0) {
        T::ok(false, $label . "：没有算出到期时刻");
        return;
    }
    $wall = wallOf($due);
    $h = (int) date("G", $wall);
    $i = (int) date("i", $wall);
    $s2 = (int) date("s", $wall);
    // 算出落点到断言之间只隔微秒，不会跨分钟，所以直接比分钟
    T::ok($h === $expHour && $i === $expMin, $label,
        "落点 " . date("m-d H:i:s", $wall) . "，期望 " . $expHour . ":" . str_pad((string) $expMin, 2, "0", STR_PAD_LEFT));
}

/** 只断言"分钟是 5 的倍数、秒为 0"，用于每 5 分钟这类表达式 */
function checkCronEvery5(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $addr = $s->spawnOf(function (): Service {
        return new Idle();
    });
    $s->every("*/5 * * * *", $addr, 1);
    $wall = wallOf($s->nextTimerDueAt());
    $i = (int) date("i", $wall);
    $sec = (int) date("s", $wall);
    T::ok($i % 5 === 0 && $sec === 0, "*/5 * * * * 落在整 5 分",
        "落点 " . date("H:i:s", $wall));
}

/** 一次性延迟不受 cron 影响，作为对照 */
function checkDelayUnaffected(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $addr = $s->spawnOf(function (): Service {
        return new Idle();
    });
    $s->timeout($addr, 1500, 1);
    $delay = $s->nextTimerDueAt() - Scheduler::nowMs();
    T::ok($delay > 1400 && $delay < 1600, "timeout(1500) 的延迟不受影响", $delay . " ms");
}

function main(): void
{
    echo "== 时钟：cron 必须按墙钟落点 ==" . PHP_EOL;

    checkCron("0 3 * * *", 3, 0, "每天 03:00");
    checkCron("30 2 * * *", 2, 30, "每天 02:30");
    checkCron("15 4 * * 1-5", 4, 15, "工作日 04:15");
    checkCronEvery5();
    checkDelayUnaffected();

    echo PHP_EOL . "passed=" . T::$pass . " failed=" . T::$fail . PHP_EOL;
}
