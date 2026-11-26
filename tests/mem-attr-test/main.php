<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 单服务内存归因（setMemGuard）。
 *
 * 这组测试要钉住的是一条**能力边界**，不是"内存有没有泄漏"：
 *   1 关闭时零开销、零记录
 *   2 单批尖峰能定位到具体服务（这是排障时唯一有用的信息）
 *   3 累计配额超限只报一次（逐批报会把磁盘写满，反而看不到第一次）
 *   4 kill 之后归因表要跟着清（id 会回绕，留着会让新服务一出生就超限）
 *   5 正常服务不误报
 *
 * 抓不到的东西也要说清楚：每次 1 KB 的缓慢增长抓不到——那需要逐分配追踪。
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

/** 每条消息分配 $mb MB 并持有引用（不持有的话分配会立刻被回收，测不出增长） */
final class Hog extends Service
{
    public int $mb = 1;

    public int $count = 0;

    /** @var array<int, string> */
    public array $blocks = [];

    public function __construct(int $mb)
    {
        $this->mb = $mb;
    }

    public function onMessage(Message $m): void
    {
        if ($this->mb > 0) {
            $this->blocks[] = str_repeat("x", $this->mb * 1048576);
        }
        $this->count++;
    }

    public function onStop(): void
    {
        $this->blocks = [];
    }
}

function memOf(Scheduler $s): int
{
    $all = $s->memByService();
    $sum = 0;
    foreach ($all as $bytes) {
        $sum += $bytes;
    }
    return $sum;
}

/** 1. 默认关闭 */
function caseOff(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    T::ok(!$s->memGuardEnabled(), "默认关闭内存守卫");

    $addr = $s->spawnOf(function (): Service {
        return new Hog(2);
    });
    $s->deliverAs(1, $addr, 1, "");
    $s->tick();
    T::ok($s->memByService() === [], "关闭时不记录任何归因");
    T::ok($s->stats()["mem_spike"] === 0, "关闭时不报尖峰");
}

/** 2. 单批尖峰能定位到服务 */
function caseSpike(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setMemGuard(4, 0);

    // 一个 8 MB 的，一个不分配的：只有前者该被抓到
    $big = $s->spawnOf(function (): Service {
        return new Hog(8);
    });
    $small = $s->spawnOf(function (): Service {
        return new Hog(0);
    });
    $s->deliverAs(1, $big, 1, "");
    $s->deliverAs(1, $small, 1, "");
    $s->tick();

    T::ok($s->memGuardEnabled(), "开启后 memGuardEnabled 为真");
    T::ok($s->stats()["mem_spike"] === 1, "8MB 单批触发 1 次尖峰", "spike=" . $s->stats()["mem_spike"]);

    $all = $s->memByService();
    // 只记正增长：不分配内存的服务不该出现在表里
    T::ok(count($all) === 1, "只有分配过内存的服务有归因记录", "n=" . count($all));
    T::ok(memOf($s) >= 8 * 1048576, "归因总量不少于 8MB", "sum=" . memOf($s));
}

/** 3. 累计配额超限，log 模式不杀 */
function caseQuotaLog(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setMemGuard(0, 3);

    $addr = $s->spawnOf(function (): Service {
        return new Hog(1);
    });
    for ($i = 0; $i < 6; $i++) {
        $s->deliverAs(1, $addr, 1, "");
        $s->tick();
    }

    T::ok(memOf($s) > 3 * 1048576, "累计超过 3MB 配额", "sum=" . memOf($s));
    T::ok($s->has($addr), "log 模式不销毁服务");
    T::ok($s->stats()["mem_kill"] === 0, "log 模式 mem_kill 为 0");
    // 6 次分配、4 次超配额，但告警只能有 1 次。
    // 逐批告警会把磁盘写满，而且把"第一次"埋在一堆重复里——那才是要查的时间点
    T::ok($s->stats()["mem_warn"] === 1, "配额告警只报一次", "warn=" . $s->stats()["mem_warn"]);
}

/** 4. kill 模式销毁服务，且归因表清干净 */
function caseQuotaKill(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setMemGuard(0, 1, "kill");

    $addr = $s->spawnOf(function (): Service {
        return new Hog(1);
    });
    for ($i = 0; $i < 4; $i++) {
        $s->deliverAs(1, $addr, 1, "");
        $s->tick();
        if (!$s->has($addr)) {
            break;
        }
    }

    T::ok(!$s->has($addr), "kill 模式：超配额的服务被销毁");
    T::ok($s->stats()["mem_kill"] === 1, "mem_kill 计数为 1", "k=" . $s->stats()["mem_kill"]);
    T::ok($s->memByService() === [], "销毁后归因记录被清理（id 会回绕，不能留债）");
}

/** 5. 正常服务不误报 */
function caseNoFalsePositive(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $s->setMemGuard(4, 8);

    $addr = $s->spawnOf(function (): Service {
        return new Hog(0);
    });
    for ($i = 0; $i < 10; $i++) {
        $s->deliverAs(1, $addr, 1, "");
    }
    $s->tick();

    T::ok($s->stats()["mem_spike"] === 0, "不分配内存的服务不触发尖峰");
    T::ok($s->stats()["mem_kill"] === 0, "不分配内存的服务不会被杀");
    T::ok($s->has($addr), "服务还活着");
}

/** 6. 参数校验 */
function caseArgs(): void
{
    Scheduler::reset();
    $s = Scheduler::instance();
    $threw = false;
    try {
        $s->setMemGuard(1, 1, "panic");
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    T::ok($threw, "action 只接受 log / kill");
}

function main(): void
{
    echo "== 单服务内存归因 ==" . PHP_EOL;
    caseOff();
    caseSpike();
    caseQuotaLog();
    caseQuotaKill();
    caseNoFalsePositive();
    caseArgs();

    echo PHP_EOL . "passed=" . T::$pass . " failed=" . T::$fail . PHP_EOL;
}
