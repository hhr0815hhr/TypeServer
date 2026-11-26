<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 调度时间片（setSlice）与 tick 总预算（setTickBudget）。
 *
 * 这两个能力的语义边界都在"消息粒度"上，所以断言按**处理顺序**做，不只数条数——
 * 条数对不代表顺序对，而顺序错才是线上的真实症状（一个慢服务把所有人的响应尾巴拉长）。
 *
 * 不需要端口：纯进程内调度，`Scheduler::tick()` 手动推进。
 */

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

/** 每条消息可选地睡一段时间，并记录自己被处理时的全局序号 */
final class Ordered extends Service
{
    public string $label = "";

    /** 每条消息的耗时（微秒）；0 = 不睡 */
    public int $costUs = 0;

    public int $count = 0;

    /** @var array<int, int> 本服务每条消息拿到时的全局序号 */
    public array $order = [];

    public function __construct(string $label, int $costUs)
    {
        $this->label = $label;
        $this->costUs = $costUs;
    }

    public function onMessage(Message $m): void
    {
        if ($this->costUs > 0) {
            usleep($this->costUs);
        }
        $this->order[] = T::$seq;
        T::$seq++;
        $this->count++;
    }
}

/**
 * 建两个服务并交替塞消息（慢的先入队，确保"不开 slice 时快的一定排在后面"）。
 *
 * @return array<int, int> [slowAddr, fastAddr]
 */
function setupPair(Scheduler $s, int $n, int $slowUs): array
{
    $slowAddr = $s->spawnOf(function () use ($slowUs): Service {
        return new Ordered("slow", $slowUs);
    });
    $fastAddr = $s->spawnOf(function (): Service {
        return new Ordered("fast", 0);
    });
    for ($i = 0; $i < $n; $i++) {
        $s->deliverAs(1, $slowAddr, 1, "");
        $s->deliverAs(1, $fastAddr, 1, "");
    }
    return [$slowAddr, $fastAddr];
}

function orderedOf(Scheduler $s, int $addr): Ordered
{
    $svc = $s->serviceOf($addr);
    if ($svc instanceof Ordered) {
        return $svc;
    }
    throw new \RuntimeException("serviceOf 没拿到 Ordered");
}

/** 1. 关闭时：慢服务完整跑完，快服务才被处理（现状基线） */
function caseOff(): void
{
    T::$seq = 0;
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setBudget(100);
    $addrs = setupPair($s, 10, 2000);
    $slowAddr = $addrs[0];
    $fastAddr = $addrs[1];
    $s->tick();
    $slow = orderedOf($s, $slowAddr);
    $fast = orderedOf($s, $fastAddr);
    $slowCount = $slow->count;
    $fastCount = $fast->count;
    $fastFirst = $fast->order[0];
    $hit = $s->stats()["slice_hit"];

    T::ok($slowCount === 10 && $fastCount === 10, "关闭 slice：两个服务都处理完");
    T::ok($fastFirst === 10, "关闭 slice：快服务排在慢服务之后", "fast.first=" . $fastFirst);
    T::ok($hit === 0, "关闭 slice：slice_hit 为 0");
    T::ok($s->sliceThresholdMs() === 0, "默认 sliceThresholdMs 为 0");
}

/** 2. 开启 slice：快服务插进慢服务中间 */
function caseOn(): void
{
    T::$seq = 0;
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setBudget(100);
    $s->setSlice(1);
    $addrs = setupPair($s, 10, 2000);
    $slowAddr = $addrs[0];
    $fastAddr = $addrs[1];
    $s->tick();
    $slow = orderedOf($s, $slowAddr);
    $fast = orderedOf($s, $fastAddr);
    $slowCount = $slow->count;
    $fastCount = $fast->count;
    $fastFirst = $fast->order[0];
    $hit = $s->stats()["slice_hit"];

    T::ok($s->sliceThresholdMs() === 1, "setSlice(1) 生效");
    T::ok($fastFirst < 10, "slice：快服务插进慢服务中间", "fast.first=" . $fastFirst);
    T::ok($slowCount === 10 && $fastCount === 10, "slice：消息一条不丢",
        "slow=" . $slowCount . " fast=" . $fastCount);
    T::ok($hit > 0, "slice：slice_hit 有计数", "hit=" . $hit);
}

/** 3. slice 只在消息边界：单条跑超预算仍要完整跑完 */
function caseCannotPreempt(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setBudget(100);
    $s->setSlice(1);
    $oneAddr = $s->spawnOf(function (): Service {
        return new Ordered("one", 5000);
    });
    $s->deliverAs(1, $oneAddr, 1, "");
    $t0 = hrtime(true);
    $s->tick();
    $elapsedMs = intdiv(hrtime(true) - $t0, 1000000);
    $one = orderedOf($s, $oneAddr);
    $oneCount = $one->count;

    T::ok($oneCount === 1, "单条消息不被 slice 打断");
    T::ok($elapsedMs >= 4, "单条消息确实跑满了 5ms", "elapsed=" . $elapsedMs . "ms");
}

/** 4. tick 总预算：一次没处理完，多次 tick 后一条不丢 */
function caseTickBudget(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setBudget(1000);
    $s->setTickBudget(2);
    $bulkAddr = $s->spawnOf(function (): Service {
        return new Ordered("bulk", 50);
    });
    for ($i = 0; $i < 500; $i++) {
        $s->deliverAs(1, $bulkAddr, 1, "");
    }
    $firstPass = $s->tick();
    $over = $s->stats()["tick_over"];

    T::ok($s->tickBudgetMs() === 2, "setTickBudget(2) 生效");
    T::ok($firstPass < 500, "tick 预算：一次没有处理完", "handled=" . $firstPass);
    T::ok($over > 0, "tick 预算：tick_over 有计数");

    $rounds = 0;
    while ($rounds < 200) {
        $n = $s->tick();
        if ($n === 0) {
            break;
        }
        $rounds++;
    }
    $bulk = orderedOf($s, $bulkAddr);
    $bulkCount = $bulk->count;
    T::ok($bulkCount === 500, "tick 预算：多次 tick 后消息不丢", "count=" . $bulkCount);
}

/** 5. 关掉开关后行为完全回到原状（可逆性） */
function caseReversible(): void
{
    T::$seq = 0;
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setBudget(100);
    $s->setSlice(1);
    $s->setSlice(0);
    $s->setTickBudget(0);
    $addrs = setupPair($s, 10, 2000);
    $slowAddr = $addrs[0];
    $fastAddr = $addrs[1];
    $s->tick();
    $fast = orderedOf($s, $fastAddr);
    $fastFirst = $fast->order[0];

    T::ok($fastFirst === 10, "关掉 slice 后回到原顺序", "fast.first=" . $fastFirst);
}

/** 6. 负值等于关闭（别让 setSlice(-1) 变成"永远让出"） */
function caseNegative(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setSlice(-1);
    $s->setTickBudget(-5);

    T::ok($s->sliceThresholdMs() === 0, "setSlice(-1) 视为关闭");
    T::ok($s->tickBudgetMs() === 0, "setTickBudget(-5) 视为关闭");
}

function main(): void
{
    echo "== 调度时间片 / tick 预算 ==" . PHP_EOL;
    caseOff();
    caseOn();
    caseCannotPreempt();
    caseTickBudget();
    caseReversible();
    caseNegative();

    echo PHP_EOL . "passed=" . T::$pass . " failed=" . T::$fail . PHP_EOL;
}
