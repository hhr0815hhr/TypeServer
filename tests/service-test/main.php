<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    /** 全局到达序号：跨服务比较"谁先被处理" */
    public static int $seq = 0;

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

/** 记录收到的东西，供断言用 */
final class Recorder extends Service
{
    /** @var array<int, array<string, mixed>> */
    public array $got = [];

    /** 收到消息时如果设置了 forwardTo，就转发过去 */
    public int $forwardTo = 0;

    /** 转发几次后停（防止自投递形成无限回环） */
    public int $forwardBudget = 0;

    /** 重入深度：验证"同一服务的处理是串行的" */
    public int $depth = 0;

    public int $maxDepth = 0;

    public int $stopCalled = 0;

    public bool $throwOnMessage = false;

    public function onStop(): void
    {
        $this->stopCalled++;
    }

    public function onMessage(Message $m): void
    {
        $this->depth++;
        if ($this->depth > $this->maxDepth) {
            $this->maxDepth = $this->depth;
        }
        $this->got[] = [
            "seq" => T::$seq++,
            "from" => $m->from,
            "msgId" => $m->msgId,
            "session" => $m->session,
            "data" => is_string($m->data) ? $m->data : "(non-string)",
        ];

        if ($this->forwardTo !== 0 && $this->forwardBudget > 0) {
            $this->forwardBudget--;
            Scheduler::instance()->deliver($this->forwardTo, 2002, "fwd", $m->session);
        }

        $this->depth--;

        if ($this->throwOnMessage) {
            throw new \RuntimeException("boom");
        }
    }
}

/** @return array<int, mixed> [Scheduler, Recorder] */
function fresh(): array
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->register("rec", function () {
        return new Recorder();
    });
    $addr = $s->spawn("rec");
    $svc = $s->serviceOf($addr);
    if (!($svc instanceof Recorder)) {
        throw new \RuntimeException("spawn 返回的不是 Recorder");
    }
    return [$s, $svc];
}

function caseBasic(): void
{
    echo "[1] 基本投递" . PHP_EOL;
    $arr = fresh();
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Recorder)) {
        return;
    }
    $addr = $svc->addr();

    T::ok($s->deliver($addr, 1001, "hello", 77), "deliver 返回 true");
    T::ok($svc->got === [], "未 tick 前不派发（异步语义）");

    $n = $s->tick();
    T::ok($n === 1, "tick 处理 1 条", "n=" . $n);
    T::ok(count($svc->got) === 1, "handler 收到 1 条");
    if (count($svc->got) === 1) {
        T::ok($svc->got[0]["msgId"] === 1001, "msgId 正确");
        T::ok($svc->got[0]["data"] === "hello", "data 正确");
        T::ok($svc->got[0]["session"] === 77, "session 透传");
        T::ok($svc->got[0]["from"] === 0, "外部发送 from=0（系统）");
    }
}

function caseBetween(): void
{
    echo PHP_EOL . "[2] 服务互发：from 应为发送方地址" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->register("rec", function () {
        return new Recorder();
    });
    $a = $s->spawn("rec");
    $b = $s->spawn("rec");
    $sa = $s->serviceOf($a);
    $sb = $s->serviceOf($b);
    if (!($sa instanceof Recorder) || !($sb instanceof Recorder)) {
        return;
    }

    $sa->forwardTo = $b;
    $sa->forwardBudget = 1;
    $s->deliver($a, 2001, "go", 9);
    $s->tick();

    T::ok(count($sa->got) === 1, "A 收到 1 条");
    T::ok(count($sb->got) === 1, "B 收到 1 条（同一次 tick 内投递并处理）");
    if (count($sb->got) === 1) {
        T::ok($sb->got[0]["from"] === $a, "B 看到的 from 是 A 的地址", "from=" . $sb->got[0]["from"]);
        T::ok($sb->got[0]["session"] === 9, "session 沿链路透传");
    }
}

function caseSelfReentrancy(): void
{
    echo PHP_EOL . "[3] 自投递：不得重入（串行性）" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->register("rec", function () {
        return new Recorder();
    });
    $a = $s->spawn("rec");
    $sa = $s->serviceOf($a);
    if (!($sa instanceof Recorder)) {
        return;
    }
    $sa->forwardTo = $a;
    $sa->forwardBudget = 1;

    $s->deliver($a, 2001, "x");
    $s->tick();

    T::ok($sa->maxDepth === 1, "处理期间没有重入", "maxDepth=" . $sa->maxDepth);
    T::ok(count($sa->got) === 2, "原消息 + 自投递消息都被处理", "count=" . count($sa->got));
    if (count($sa->got) === 2) {
        T::ok($sa->got[1]["from"] === $a, "自投递的 from 是自己");
    }
}

function caseKill(): void
{
    echo PHP_EOL . "[4] 销毁服务" . PHP_EOL;
    $arr = fresh();
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Recorder)) {
        return;
    }
    $addr = $svc->addr();

    $s->deliver($addr, 1001, "before");
    $s->tick();
    T::ok(count($svc->got) === 1, "销毁前正常");

    T::ok($s->kill($addr), "kill 返回 true");
    T::ok($svc->stopCalled === 1, "onStop 被调用一次");
    T::ok(!$s->has($addr), "has() 变为 false");
    T::ok(!$s->deliver($addr, 1001, "after"), "销毁后 deliver 返回 false（不静默丢）");
    T::ok($s->stats()["undelivered"] >= 1, "undelivered 计数增加");
    T::ok(!$s->kill($addr), "重复 kill 返回 false");
}

function caseErrorIsolation(): void
{
    echo PHP_EOL . "[5] 错误隔离：一个服务抛异常，不影响其他服务" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->register("rec", function () {
        return new Recorder();
    });
    $a = $s->spawn("rec");
    $b = $s->spawn("rec");
    $sa = $s->serviceOf($a);
    $sb = $s->serviceOf($b);
    if (!($sa instanceof Recorder) || !($sb instanceof Recorder)) {
        return;
    }
    $sa->throwOnMessage = true;

    $s->deliver($a, 1001, "boom");
    $s->deliver($b, 1001, "fine");
    $s->tick();

    T::ok(!$s->has($a), "出错的服务被销毁");
    T::ok($sa->stopCalled === 1, "出错服务的 onStop 被调用");
    T::ok($s->has($b), "另一个服务仍在");
    T::ok(count($sb->got) === 1, "另一个服务正常处理了消息");
    T::ok($s->stats()["errors"] === 1, "错误计数为 1");
}

function caseFairness(): void
{
    echo PHP_EOL . "[6] 公平性：busy 服务不能饿死 idle 服务" . PHP_EOL;

    // (a) 单轮处理量受 budget 约束
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setBudget(100);
    $s->register("rec", function () {
        return new Recorder();
    });
    $one = $s->spawn("rec");
    for ($i = 0; $i < 1000; $i++) {
        $s->deliver($one, 1001, "b");
    }
    $handled = $s->tick(1);
    $so = $s->serviceOf($one);
    if ($so instanceof Recorder) {
        T::ok(count($so->got) === 100, "一轮最多处理 budget 条", "count=" . count($so->got));
        T::ok($handled === 100, "tick 返回值与之一致", "handled=" . $handled);
    }

    // (b) 交叉：idle 的那条要在 busy 排空之前被处理
    Scheduler::reset();
    $s2 = Scheduler::instance();
    $s2->setBudget(100);
    $s2->register("rec", function () {
        return new Recorder();
    });
    $busy = $s2->spawn("rec");
    $idle = $s2->spawn("rec");

    for ($i = 0; $i < 1000; $i++) {
        $s2->deliver($busy, 1001, "b");
    }
    $s2->deliver($idle, 1001, "i");

    while ($s2->tick() > 0) {
    }

    $sb = $s2->serviceOf($busy);
    $si = $s2->serviceOf($idle);
    if (!($sb instanceof Recorder) || !($si instanceof Recorder)) {
        return;
    }
    T::ok(count($sb->got) === 1000, "busy 全部处理完", "count=" . count($sb->got));
    T::ok(count($si->got) === 1, "idle 也被处理");
    if (count($sb->got) > 0 && count($si->got) === 1) {
        $lastBusySeq = $sb->got[count($sb->got) - 1]["seq"];
        $idleSeq = $si->got[0]["seq"];
        T::ok($idleSeq < $lastBusySeq, "idle 插在 busy 中间被处理（没等它排空）",
            "idleSeq=" . $idleSeq . " lastBusySeq=" . $lastBusySeq);
    }
}

function caseOverflow(): void
{
    echo PHP_EOL . "[7] 信箱上限：积压超限时丢弃而不是 OOM" . PHP_EOL;
    $arr = fresh();
    $s = $arr[0];
    $svc = $arr[1];
    if (!($s instanceof Scheduler) || !($svc instanceof Recorder)) {
        return;
    }
    $addr = $svc->addr();
    $s->setMailboxLimit(4);

    $accepted = 0;
    for ($i = 0; $i < 10; $i++) {
        if ($s->deliver($addr, 1001, "x")) {
            $accepted++;
        }
    }
    T::ok($accepted === 4, "只接受了 4 条", "accepted=" . $accepted);
    T::ok($s->stats()["overflow"] === 6, "6 条被丢弃并计数", "overflow=" . $s->stats()["overflow"]);

    $s->tick();
    T::ok(count($svc->got) === 4, "已接受的消息正常处理", "count=" . count($svc->got));

    // 处理完后再投递应恢复
    T::ok($s->deliver($addr, 1001, "again"), "队列排空后可以继续投递");
}

function main(): void
{
    echo "== Service 功能测试 ==" . PHP_EOL;
    caseBasic();
    caseBetween();
    caseSelfReentrancy();
    caseKill();
    caseErrorIsolation();
    caseFairness();
    caseOverflow();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
