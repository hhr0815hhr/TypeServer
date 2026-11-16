<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

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

/** 纯 CPU 忙等，模拟"处理一条消息花太久" */
function burn(int $ms): int
{
    $t = hrtime(true);
    $limit = $ms * 1000000;
    $n = 0;
    while (hrtime(true) - $t < $limit) {
        $n++;
    }
    return $n;
}

final class Slow extends Service
{
    public int $burnMs = 0;

    public int $handled = 0;

    public int $stopCalled = 0;

    public function onStop(): void
    {
        $this->stopCalled++;
    }

    public function onMessage(Message $m): void
    {
        $this->handled++;
        if ($this->burnMs > 0) {
            burn($this->burnMs);
        }
    }
}

/** @return array<int, mixed> [Scheduler, Slow] */
function freshSlow(int $burnMs): array
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->register("slow", function () {
        return new Slow();
    });
    $addr = $s->spawn("slow");
    $svc = $s->serviceOf($addr);
    if (!($svc instanceof Slow)) {
        throw new \RuntimeException("spawn 失败");
    }
    $svc->burnMs = $burnMs;
    return [$s, $svc];
}

function caseOff(): void
{
    echo "[1] 关闭看门狗：慢消息也不记账" . PHP_EOL;
    $arr = freshSlow(10);
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Slow)) {
        return;
    }
    $s->deliver($svc->addr(), 1001, "x");
    $s->tick();
    T::ok($svc->handled === 1, "消息已处理");
    T::ok($s->stats()["slow"] === 0, "slow 计数为 0", "slow=" . $s->stats()["slow"]);
    T::ok($s->stats()["max_msg_us"] === 0, "max_msg_us 未统计");
}

function caseLog(): void
{
    echo PHP_EOL . "[2] log 模式：记录 + 告警，服务保留" . PHP_EOL;
    $arr = freshSlow(12);
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Slow)) {
        return;
    }
    $s->setWatchdog(5, "log");
    $addr = $svc->addr();
    $s->deliver($addr, 1001, "x");
    $s->tick();

    T::ok($svc->handled === 1, "消息已处理完（不会被中断）");
    T::ok($s->stats()["slow"] === 1, "slow 计数为 1", "slow=" . $s->stats()["slow"]);
    T::ok($s->stats()["max_msg_us"] >= 12000, "max_msg_us 记录了真实耗时",
        "us=" . $s->stats()["max_msg_us"]);
    T::ok($s->has($addr), "服务仍然存在（log 不杀服务）");
}

function caseKill(): void
{
    echo PHP_EOL . "[3] kill 模式：销毁服务，后续消息不再处理" . PHP_EOL;
    $arr = freshSlow(12);
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Slow)) {
        return;
    }
    $s->setWatchdog(5, "kill");
    $addr = $svc->addr();
    $s->deliver($addr, 1001, "a");
    $s->deliver($addr, 1001, "b");
    $s->deliver($addr, 1001, "c");
    $s->tick();

    T::ok(!$s->has($addr), "服务被销毁");
    T::ok($svc->stopCalled === 1, "onStop 被调用");
    T::ok($svc->handled === 1, "只处理了第一条就停", "handled=" . $svc->handled);
    T::ok($s->stats()["slow"] === 1, "只记一次慢消息");
    T::ok(!$s->deliver($addr, 1001, "d"), "销毁后投递返回 false");
}

function caseNoFalsePositive(): void
{
    echo PHP_EOL . "[4] 阈值之上的快消息不误报" . PHP_EOL;
    $arr = freshSlow(1);
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Slow)) {
        return;
    }
    $s->setWatchdog(50, "log");
    for ($i = 0; $i < 5; $i++) {
        $s->deliver($svc->addr(), 1001, "x");
    }
    $s->tick();
    T::ok($svc->handled === 5, "5 条都处理了");
    T::ok($s->stats()["slow"] === 0, "没有误报", "slow=" . $s->stats()["slow"]);
}

function caseInvalidAction(): void
{
    echo PHP_EOL . "[5] 参数校验" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $threw = false;
    try {
        $s->setWatchdog(10, "boom");
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    T::ok($threw, "非法 action 报错");
    $s->setWatchdog(0);
    T::ok($s->watchdogThresholdMs() === 0, "0 表示关闭");
}

/**
 * 开/关看门狗的吞吐对比：每个消息多两次 hrtime，代价要量出来而不是估。
 * 阈值设很大，保证不会真的触发告警（只测计时开销）。
 */
function caseOverhead(): void
{
    echo PHP_EOL . "[6] 开启后的吞吐代价（每档 3 轮取中位）" . PHP_EOL;
    $off = [];
    $on = [];
    for ($r = 0; $r < 3; $r++) {
        $off[] = throughputOnce(false);
        $on[] = throughputOnce(true);
    }
    sort($off);
    sort($on);
    $mOff = $off[1];
    $mOn = $on[1];
    echo "  关闭          " . number_format($mOff, 0) . " msg/s（单条 " . number_format(1e9 / $mOff, 0) . " ns）" . PHP_EOL;
    echo "  开启          " . number_format($mOn, 0) . " msg/s（单条 " . number_format(1e9 / $mOn, 0) . " ns）" . PHP_EOL;
    echo "  代价          " . number_format((1e9 / $mOn) - (1e9 / $mOff), 0) . " ns/条（"
        . number_format(((1e9 / $mOn) / (1e9 / $mOff) - 1) * 100, 1) . "%）" . PHP_EOL;
}

function throughputOnce(bool $watchdog): float
{
    Scheduler::reset();
    $s = Scheduler::instance();
    if ($watchdog) {
        $s->setWatchdog(1000, "log");
    }
    $s->register("slow", function () {
        return new Slow();
    });
    $addr = $s->spawn("slow");
    $n = 50000;

    for ($i = 0; $i < 5000; $i++) {
        $s->deliver($addr, 1001, "w");
    }
    $s->tick();

    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $s->deliver($addr, 1001, "x");
    }
    $s->tick();
    $t1 = hrtime(true);
    return $n / (($t1 - $t0) / 1e9);
}

function main(): void
{
    echo "== 慢消息看门狗测试 ==" . PHP_EOL;
    caseOff();
    caseLog();
    caseKill();
    caseNoFalsePositive();
    caseInvalidAction();
    caseOverhead();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
