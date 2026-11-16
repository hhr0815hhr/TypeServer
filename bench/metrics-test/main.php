<?php

/**
 * T6-04 验证：埋点、聚合、慢调用自动打点。
 *
 * 三件事必须成立：
 *   1. 分位数是**桶上界**，误差 ≤19%，不随量级变化
 *   2. 慢于阈值要自动打点，而且**一定要限流**（否则卡顿会把日志写满）
 *   3. 埋点本身够便宜（不能成为被测对象的一部分）
 */

use TSF\Kernel\Logger;
use TSF\Metrics\Metrics;
use TSF\Metrics\Reporter;
use TSF\Service\Scheduler;

const LOG_FILE = "/tmp/metrics_test.log";

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

    public static function near(float $got, float $want, float $tol, string $label): void
    {
        $ok = abs($got - $want) <= $tol;
        self::ok($ok, $label, "got=" . number_format($got, 2) . " want=" . number_format($want, 2)
            . " ±" . number_format($tol, 2));
    }
}

function logText(): string
{
    $s = @file_get_contents(LOG_FILE);
    return $s === false ? "" : $s;
}

function countLog(string $needle): int
{
    return substr_count(logText(), $needle);
}

function testCounter(): void
{
    echo "== [1] 计数器与瞬时值 ==" . PHP_EOL;
    Metrics::reset();

    Metrics::inc("packet.in");
    Metrics::inc("packet.in");
    Metrics::inc("packet.in", 10);
    Metrics::add("bytes.out", 1500);

    Metrics::gauge("room.active", 120);
    Metrics::gauge("room.active", 90);    // 当前值变小
    Metrics::gauge("room.active", 150);   // 又变大

    $snap = Metrics::snapshot();
    $by = [];
    foreach ($snap as $s) {
        $by[$s->name] = $s;
    }

    T::eq(isset($by["packet.in"]), true, "计数器出现在快照里");
    if (isset($by["packet.in"])) {
        T::eq($by["packet.in"]->kind, "counter", "类型是 counter");
        T::eq((int) $by["packet.in"]->value, 12, "3 次 inc 累加成 12");
    }
    if (isset($by["bytes.out"])) {
        T::eq((int) $by["bytes.out"]->value, 1500, "add 累加");
    }
    if (isset($by["room.active"])) {
        T::eq($by["room.active"]->kind, "gauge", "类型是 gauge");
        T::eq((int) $by["room.active"]->value, 150, "gauge 记最后的值");
        T::eq((int) $by["room.active"]->max, 150, "gauge 也记峰值");
    }

    // 快照按名字排序，日志表格才稳定
    $names = [];
    foreach ($snap as $s) {
        $names[] = $s->name;
    }
    $sorted = $names;
    sort($sorted);
    T::eq(implode(",", $names), implode(",", $sorted), "快照按名字排序");
}

function testHistogram(): void
{
    echo "== [2] 直方图：分位数是桶上界，误差 ≤19% ==" . PHP_EOL;
    Metrics::reset();

    // 单一量级：1000 µs
    for ($i = 0; $i < 1000; $i++) {
        Metrics::observe("fixed.ms", 1000.0);
    }
    $snap = Metrics::snapshot();
    $s = $snap[0];
    T::eq($s->count, 1000, "样本数");
    T::near($s->p50, 1000.0, 190.0, "p50 ≈ 1000µs（桶上界，误差 ≤19%）");
    T::eq($s->max, 1000.0, "max 是精确值，不是桶上界");

    // 跨量级：10 个 10µs + 90 个 1000µs → p50 应当落在 1000 那一档附近
    Metrics::reset();
    for ($i = 0; $i < 10; $i++) {
        Metrics::observe("mixed", 10.0);
    }
    for ($i = 0; $i < 90; $i++) {
        Metrics::observe("mixed", 1000.0);
    }
    $snap = Metrics::snapshot();
    $s = $snap[0];
    T::near($s->p50, 1000.0, 190.0, "p50 落在 1ms 档（10% 的 10µs 样本影响不到中位数）");
    T::ok($s->p99 >= 1000.0 && $s->p99 <= 1190.0, "p99 也在 1ms 档", number_format($s->p99, 0) . "µs");

    // 极小值：不会落到负桶或 0
    Metrics::reset();
    Metrics::observe("tiny", 0.5);
    $snap = Metrics::snapshot();
    T::ok($snap[0]->p50 >= 1.0, "0.5µs 落在第一档（上界 1.19µs）", number_format($snap[0]->p50, 2));

    // 大值：1 秒
    Metrics::reset();
    Metrics::observe("huge", 1000000.0);
    $snap = Metrics::snapshot();
    T::near($snap[0]->p50, 1000000.0, 190000.0, "1 秒的样本也在 19% 误差内");
}

function testSlowLog(): void
{
    echo "== [3] 慢调用自动打点 ==" . PHP_EOL;
    Metrics::reset();
    @unlink(LOG_FILE);
    Logger::init("metrics-test", LOG_FILE, Logger::DEBUG, false);

    Metrics::watch("battle.tick", 5000.0);

    // 快的：不该有日志
    Metrics::observe("battle.tick", 1200.0);
    Metrics::observe("battle.tick", 3000.0);
    T::eq(countLog("slow: battle.tick"), 0, "没超阈值不打日志");

    // 慢的：打一条
    Metrics::observe("battle.tick", 9000.0);
    T::eq(countLog("slow: battle.tick"), 1, "超阈值打了一条");
    T::ok(str_contains(logText(), "\"limit_us\":5000"), "日志里带阈值");
    T::ok(str_contains(logText(), "\"us\":9000"), "日志里带实际耗时");

    // 上下文
    Metrics::slowLogIntervalMs(0);   // 先关限流，验证 ctx 透传
    Metrics::observe("battle.tick", 8000.0, "room=1234 uid=88");
    T::ok(str_contains(logText(), "room=1234 uid=88"), "ctx 透传进日志");

    // 不设阈值的指标不检查
    Metrics::slowLogIntervalMs(1000);
    Metrics::observe("no.threshold", 999999.0);
    T::eq(countLog("slow: no.threshold"), 0, "没 watch 的指标不自动打点");
}

function testSlowRateLimit(): void
{
    echo "== [4] 慢日志必须限流 ==" . PHP_EOL;
    Metrics::reset();
    @unlink(LOG_FILE);
    Logger::init("metrics-test", LOG_FILE, Logger::DEBUG, false);

    Metrics::slowLogIntervalMs(60000);   // 一分钟内只允许一条
    Metrics::watch("stuck", 100.0);

    for ($i = 0; $i < 50; $i++) {
        Metrics::observe("stuck", 500.0);
    }
    T::eq(countLog("slow: stuck"), 1, "50 次慢调用只写 1 条日志");

    $snap = Metrics::snapshot();
    T::eq($snap[0]->suppressed, 49, "被压掉的 49 次记在 suppressed 里");

    // 下一条日志应当带上被压掉的数量
    Metrics::slowLogIntervalMs(0);
    Metrics::observe("stuck", 500.0);
    T::ok(str_contains(logText(), "\"suppressed\":49"), "下一条日志带上 suppressed");

    // 直方图本身不受限流影响：**51 次全都记进去了**（快照要重新取，
    // 上面那份是 50 次时的）
    $snap2 = Metrics::snapshot();
    T::eq($snap2[0]->count, 51, "限流只影响日志，样本一次不少");
}

function testTable(): void
{
    echo "== [5] 文本表格 ==" . PHP_EOL;
    Metrics::reset();
    Metrics::inc("packets.in", 1234567);
    Metrics::gauge("room.active", 88);
    Metrics::watch("rpc.echo", 1000.0);
    for ($i = 0; $i < 10; $i++) {
        Metrics::observe("rpc.echo", 220.0);
    }

    $t = Metrics::table();
    T::ok(str_contains($t, "NAME"), "有表头");
    T::ok(str_contains($t, "packets.in"), "计数器在表里");
    T::ok(str_contains($t, "1.23M"), "大数字缩写成 M");
    T::ok(str_contains($t, "room.active"), "gauge 在表里");
    T::ok(str_contains($t, "rpc.echo"), "直方图在表里");
    T::ok(str_contains($t, "µs") || str_contains($t, "ms"), "耗时带单位");

    // 每行宽度一致（对齐靠 sprintf 的宽度，中文不能进表头）
    $lines = explode(PHP_EOL, trim($t));
    $w = strlen($lines[1]);
    $same = true;
    for ($i = 1; $i < count($lines); $i++) {
        if (strlen($lines[$i]) !== $w) {
            $same = false;
        }
    }
    T::ok($same, "所有行等宽（表格对齐）", "首行 " . $w . " 字节");
}

function testReporter(): void
{
    echo "== [6] 定期上报 ==" . PHP_EOL;
    Metrics::reset();
    @unlink(LOG_FILE);
    Logger::init("metrics-test", LOG_FILE, Logger::DEBUG, false);

    Scheduler::reset();
    $s = Scheduler::instance();
    Metrics::inc("tick.count", 7);

    $addr = $s->spawnOf(function (): Reporter {
        return new Reporter(50, false);
    });
    // 跑 ~150ms，应当至少 dump 两次
    $deadline = Scheduler::nowMs() + 150;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(2000);
    }

    $r = $s->serviceOf($addr);
    $dumps = 0;
    if ($r instanceof Reporter) {
        $dumps = $r->dumps();
    }
    T::ok($dumps >= 1, "Reporter 至少上报一次", "dumps=" . $dumps);
    T::ok(countLog("metrics: ") >= 1, "日志里有表格");

    // 累积模式下计数不清零
    $snap = Metrics::snapshot();
    $found = false;
    foreach ($snap as $x) {
        if ($x->name === "tick.count" && (int) $x->value === 7) {
            $found = true;
        }
    }
    T::ok($found, "累积模式：上报后计数还在");
}

function testOverhead(): void
{
    echo "== [7] 埋点开销 ==" . PHP_EOL;
    Metrics::reset();
    Metrics::watch("bench.op", 100000.0);

    // 取多次中的最小值：共享 VM 上单次测量会被别的进程带偏
    $bestInc = 1.0e9;
    $bestObs = 1.0e9;
    $n = 200000;

    for ($round = 0; $round < 3; $round++) {
        $t0 = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            Metrics::inc("bench.counter");
        }
        $ns = (float) (hrtime(true) - $t0) / (float) $n;
        if ($ns < $bestInc) {
            $bestInc = $ns;
        }

        $t1 = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            Metrics::observe("bench.op", 42.0);
        }
        $ns2 = (float) (hrtime(true) - $t1) / (float) $n;
        if ($ns2 < $bestObs) {
            $bestObs = $ns2;
        }
    }

    printf("  inc:     %.0f ns/次\n", $bestInc);
    printf("  observe: %.0f ns/次\n", $bestObs);

    // observe 里最可疑的是 log()：证明一下到底是不是它贵，
    // 而不是凭感觉写进文档
    $bestLog = 1.0e9;
    for ($round = 0; $round < 3; $round++) {
        $t2 = hrtime(true);
        $acc = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $acc += log(42.0 + (float) $i);
        }
        $ns3 = (float) (hrtime(true) - $t2) / (float) $n;
        if ($ns3 < $bestLog) {
            $bestLog = $ns3;
        }
    }
    printf("  log():   %.0f ns/次（observe 里唯一的重活）\n", $bestLog);
    printf("  done:    （含 hrtime 两次调用，约 %.0f ns）\n", $bestObs + 60.0);

    T::ok($bestInc < 300.0, "inc < 300 ns", number_format($bestInc, 0) . " ns");
    T::ok($bestObs < 800.0, "observe < 800 ns", number_format($bestObs, 0) . " ns");
}

function main(): void
{
    testCounter();
    testHistogram();
    testSlowLog();
    testSlowRateLimit();
    testTable();
    testReporter();
    testOverhead();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
