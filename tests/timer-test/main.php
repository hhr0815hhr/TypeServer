<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;
use TSF\Timer\Cron;

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

/** 记录收到的定时器消息（msgId + 到达时刻） */
final class Clock extends Service
{
    /** @var array<int, array{0:int,1:int}> [msgId, 到达毫秒] */
    public array $hits = [];

    public function onMessage(Message $m): void
    {
        $this->hits[] = [$m->msgId, Scheduler::nowMs()];
    }

    public function count(int $msgId): int
    {
        $n = 0;
        foreach ($this->hits as $h) {
            if ($h[0] === $msgId) {
                $n++;
            }
        }
        return $n;
    }
}

/** @return array<int, mixed> [Scheduler, Clock] */
function freshClock(): array
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->register("clock", function () {
        return new Clock();
    });
    $addr = $s->spawn("clock");
    $svc = $s->serviceOf($addr);
    if (!($svc instanceof Clock)) {
        throw new \RuntimeException("spawn 失败");
    }
    return [$s, $svc];
}

function caseTimeout(): void
{
    echo "[1] 一次性定时" . PHP_EOL;
    $arr = freshClock();
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Clock)) {
        return;
    }
    $addr = $svc->addr();

    $fired = 0;
    $tid = $s->timeout($addr, 40, 9001);
    T::ok($tid > 0, "返回定时器 id");

    $s->tick();
    T::ok(count($svc->hits) === 0, "未到点不触发");

    usleep(60000);
    $fired += $s->tick();
    T::ok(count($svc->hits) === 1, "到点触发一次", "count=" . count($svc->hits));

    usleep(60000);
    $s->tick();
    T::ok(count($svc->hits) === 1, "一次性定时不会重复触发");

    if ($fired < 0) {
        echo "";
    }
}

function caseInterval(): void
{
    echo PHP_EOL . "[2] 周期定时" . PHP_EOL;
    $arr = freshClock();
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Clock)) {
        return;
    }
    $addr = $svc->addr();

    $t0 = Scheduler::nowMs();
    $tid = $s->interval($addr, 20, 9002);

    $deadline = $t0 + 160;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(2000);
    }
    $n = $svc->count(9002);
    T::ok($n >= 6 && $n <= 9, "160ms / 20ms 应触发 7 次左右", "n=" . $n);

    // 取消后不再增长
    T::ok($s->cancelTimer($tid), "cancel 返回 true");
    $before = $svc->count(9002);
    $deadline2 = Scheduler::nowMs() + 100;
    while (Scheduler::nowMs() < $deadline2) {
        $s->tick();
        usleep(2000);
    }
    T::ok($svc->count(9002) === $before, "取消后不再触发", "before=" . $before);
    T::ok(!$s->cancelTimer($tid), "重复 cancel 返回 false");
}

function casePeriodNoDrift(): void
{
    echo PHP_EOL . "[3] 周期不漂移：第 5 次触发仍应贴近 5×周期" . PHP_EOL;
    $arr = freshClock();
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Clock)) {
        return;
    }
    $addr = $svc->addr();

    $t0 = Scheduler::nowMs();
    $s->interval($addr, 30, 9003);
    $deadline = $t0 + 200;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(1000);
    }

    $hits = [];
    foreach ($svc->hits as $h) {
        if ($h[0] === 9003) {
            $hits[] = $h[1] - $t0;
        }
    }
    T::ok(count($hits) >= 5, "至少触发 5 次", "n=" . count($hits));
    if (count($hits) >= 5) {
        $offset5 = $hits[4];
        T::ok($offset5 >= 140 && $offset5 <= 175, "第 5 次在 150ms 附近（累计漂移 < 20ms）",
            "第5次=" . $offset5 . "ms");
    }
}

function caseKillCancels(): void
{
    echo PHP_EOL . "[4] 销毁服务时撤销其定时器" . PHP_EOL;
    $arr = freshClock();
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Clock)) {
        return;
    }
    $addr = $svc->addr();

    $s->timeout($addr, 20, 9004);
    $s->interval($addr, 20, 9005);
    T::ok($s->timers()->size() === 2, "注册了 2 个定时器", "size=" . $s->timers()->size());

    $s->kill($addr);
    T::ok($s->timers()->size() === 0, "服务销毁后定时器被清空", "size=" . $s->timers()->size());

    usleep(50000);
    $s->tick();
    T::ok(count($svc->hits) === 0, "死服务的定时器不会投递消息");
    T::ok($s->stats()["timers_cancelled"] === 2, "撤销计数为 2", "n=" . $s->stats()["timers_cancelled"]);
}

function caseCronCompute(): void
{
    echo PHP_EOL . "[5] cron 计算（按性质断言，不写死时间戳）" . PHP_EOL;

    // 每天 5:00
    $base = mktime(12, 34, 0, 6, 15, 2026);
    $next = Cron::next("0 5 * * *", $base);
    T::ok(date("H:i", $next) === "05:00", "每天 5:00", "got=" . date("Y-m-d H:i", $next));
    T::ok($next > $base && $next - $base <= 86400, "落在 24 小时内");

    // 每 15 分钟
    $next = Cron::next("*/15 * * * *", $base);
    T::ok((int) date("i", $next) % 15 === 0 && (int) date("s", $next) === 0, "*/15 对齐到 0/15/30/45",
        "got=" . date("H:i:s", $next));

    // 每月 1 号 0:00
    $next = Cron::next("0 0 1 * *", $base);
    T::ok((int) date("j", $next) === 1 && date("H:i", $next) === "00:00", "每月 1 号 0:00",
        "got=" . date("Y-m-d H:i", $next));

    // 每周一 0:00
    $next = Cron::next("0 0 * * 1", $base);
    T::ok((int) date("w", $next) === 1 && date("H:i", $next) === "00:00", "每周一 0:00",
        "got=" . date("Y-m-d H:i", $next));

    // 2 月 29 日 → 下一个闰年
    $next = Cron::next("0 0 29 2 *", $base);
    T::ok((int) date("n", $next) === 2 && (int) date("j", $next) === 29, "2 月 29 日（闰年）",
        "got=" . date("Y-m-d H:i", $next));

    // 工作日 9:30
    $next = Cron::next("30 9 * * 1-5", $base);
    $w = (int) date("w", $next);
    T::ok($w >= 1 && $w <= 5 && date("H:i", $next) === "09:30", "工作日 9:30",
        "got=" . date("Y-m-d H:i", $next));

    // 日和星期都限定时取"或"：每月 1 号 或 每周一
    $next = Cron::next("0 0 1 * 1", $base);
    $isFirst = (int) date("j", $next) === 1;
    $isMon = (int) date("w", $next) === 1;
    T::ok($isFirst || $isMon, "日/周同时限定时取或", "got=" . date("Y-m-d H:i", $next));

    // 非法表达式
    $threw = false;
    try {
        Cron::next("0 0 * *", $base);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    T::ok($threw, "字段数不对时报错");

    $threw = false;
    try {
        Cron::next("99 * * * *", $base);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    T::ok($threw, "取值越界时报错");
}

function caseCronRegister(): void
{
    echo PHP_EOL . "[6] cron 注册：到期时刻应对齐到分钟边界且在未来" . PHP_EOL;
    $arr = freshClock();
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Clock)) {
        return;
    }
    $addr = $svc->addr();

    $tid = $s->every("*/5 * * * *", $addr, 9006);
    T::ok($tid > 0, "注册成功");
    $due = $s->timers()->nextDueAt();
    // 断言落点要对齐整分钟，但**不能直接看 $due % 60000**：$due 是单调毫秒
    // （hrtime 基准），它的绝对值跟纪元无关，整齐与否是旧实现（Unix 秒 × 1000）
    // 的偶然特征。换算成墙钟再看才是真行为
    $wallMs = (int) (microtime(true) * 1000.0) + ($due - Scheduler::nowMs());
    $wallSec = intdiv($wallMs, 1000);
    T::ok($wallSec % 60 === 0, "到期时刻落在整分钟", "墙钟 " . date("H:i:s", $wallSec));
    T::ok($due > Scheduler::nowMs(), "到期时刻在未来");

    $threw = false;
    try {
        $s->every("0 0 31 2 *", $addr, 9007);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    T::ok($threw, "永不触发的表达式直接报错");
}

/**
 * 虚拟时钟：due() 接受显式时间戳，可以不睡觉就把时钟推快，
 * 用来测"远端补位""大跳变""周期重排"这些真实时间里要等很久的路径。
 */
function caseVirtualClock(): void
{
    echo PHP_EOL . "[7] 虚拟时钟：远端 / 大跳变 / 周期重排" . PHP_EOL;
    $base = 1000000;

    // (a) 超出轮窗口（4096ms）→ 走远端堆，进入窗口后补位再触发
    $t = new \TSF\Timer\Timers();
    $t->add($base, 7, $base + 4200, 1, "far");
    $fired = 0;
    $firedAt = 0;
    for ($ms = 500; $ms <= 4500; $ms += 500) {
        $out = $t->due($base + $ms);
        foreach ($out as $x) {
            $fired++;
            $firedAt = $base + $ms;
        }
    }
    T::ok($fired === 1, "远端定时器最终触发一次", "fired=" . $fired);
    T::ok($firedAt === $base + 4500, "在第一次越过到期点的调用里触发", "at=" . ($firedAt - $base) . "ms");

    // (b) 大跳变：一次性推进 100 秒，中间所有到期项都要出来
    $t2 = new \TSF\Timer\Timers();
    $t2->add($base, 7, $base + 100, 1, "a");
    $t2->add($base, 7, $base + 3000, 2, "b");
    $t2->add($base, 7, $base + 90000, 3, "c");
    $t2->add($base, 7, $base + 200000, 4, "d");
    $out = $t2->due($base + 100000);
    T::ok(count($out) === 3, "跳变后取出 3 条到期的", "n=" . count($out));
    T::ok($t2->size() === 1, "剩下 1 条未到期", "size=" . $t2->size());
    $out2 = $t2->due($base + 300000);
    T::ok(count($out2) === 1, "再推进后最后一条也触发");

    // (c) 周期重排：虚拟时钟下 100ms 周期，推 1000ms 应触发 10 次
    $t3 = new \TSF\Timer\Timers();
    $t3->add($base, 7, $base + 100, 5, "tick", 100);
    $n = 0;
    $times = [];
    for ($ms = 1; $ms <= 1000; $ms++) {
        $o = $t3->due($base + $ms);
        foreach ($o as $x) {
            $n++;
            $times[] = $ms;
        }
    }
    T::ok($n === 10, "1000ms 内触发 10 次", "n=" . $n);
    if ($n >= 2) {
        T::ok($times[1] - $times[0] === 100, "触发间隔精确 100ms",
            "gap=" . ($times[1] - $times[0]));
    }
    T::ok($t3->size() === 1, "周期定时器仍在（重排成功）", "size=" . $t3->size());

    // (d) 撤销后虚拟时钟推进也不再触发
    $t3->cancel(1);
    $n2 = 0;
    for ($ms = 1001; $ms <= 2000; $ms++) {
        $n2 += count($t3->due($base + $ms));
    }
    T::ok($n2 === 0, "撤销后不再触发", "n=" . $n2);
    T::ok($t3->size() === 0, "撤销后表为空", "size=" . $t3->size());
}

function main(): void
{
    echo "== 定时器功能测试 ==" . PHP_EOL;
    caseTimeout();
    caseInterval();
    casePeriodNoDrift();
    caseKillCancels();
    caseCronCompute();
    caseCronRegister();
    caseVirtualClock();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
