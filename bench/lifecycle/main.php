<?php

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/**
 * 生命周期稳定性：反复创建/销毁 Service，内存会不会回到基线。
 *
 * ## 为什么这一项最该测
 *
 * 框架推荐的用法就是"一房一 Service"——棋牌每局建房、副本每次进本都建一个、
 * 结算完销毁。如果 spawn/kill 每轮残留几十字节，跑一周就会把 node 吃满，
 * 而这类问题的现象是"服务越来越慢、最后 OOM"，与任何一次具体改动都对不上号。
 *
 * ## 测法
 *
 * 先跑一轮预热（类表、日志缓冲这些一次性分配要算进基线），然后反复
 * spawn N 个 → 全部 kill，每轮打印相对基线的增量。
 *
 * **判据是"后几轮不再增长"，不是"增量等于 0"**：第一轮通常会有一些
 * 一次性的池化分配（定时器槽、id 记录环），第二轮起必须完全平坦。
 * 单调上升就是泄漏。
 */

final class Idle extends Service
{
    public function onMessage(Message $m): void
    {
    }
}

/** 带一个 50ms 一次性定时器，模拟"入场后开一个倒计时" */
final class Ticker extends Service
{
    public function onStart(): void
    {
        $this->timeout(50, 1);
    }

    public function onMessage(Message $m): void
    {
    }
}

/** 和 Ticker 一模一样但不建定时器，用来二分"增长到底来自定时器还是别的" */
final class TickerNoTimer extends Service
{
    public function onStart(): void
    {
    }

    public function onMessage(Message $m): void
    {
    }
}

/** @return array<int, int> */
function spawnBatch(Scheduler $s, int $n): array
{
    $addrs = [];
    for ($i = 0; $i < $n; $i++) {
        $addrs[] = $s->spawnOf(function (): Service {
            return new Idle();
        });
    }
    return $addrs;
}

/**
 * @param array<int, int> $addrs
 */
function killBatch(Scheduler $s, array $addrs): void
{
    foreach ($addrs as $a) {
        $s->kill($a);
    }
}

function caseChurn(): void
{
    echo "== [1] spawn / kill 循环（每轮 5000 个）==" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();

    // 预热：让类表、日志缓冲、定时器槽的一次性分配先发生。
    // 预热规模必须 >= 正式轮的规模，否则第一轮会把"峰值上移"误算成增长
    $warm = spawnBatch($s, 5000);
    killBatch($s, $warm);
    unset($warm);
    $s->tick();

    $base = memory_get_usage(false);
    $prev = $base;
    $flat = 0;
    for ($r = 0; $r < 8; $r++) {
        $addrs = spawnBatch($s, 5000);
        killBatch($s, $addrs);
        unset($addrs);
        $s->tick();

        $now = memory_get_usage(false);
        $delta = $now - $base;
        $grow = $now - $prev;
        echo "  第 " . ($r + 1) . " 轮：相对基线 " . ($delta >= 0 ? "+" : "") . $delta
            . " B，相对上轮 " . ($grow >= 0 ? "+" : "") . $grow . " B" . PHP_EOL;
        if ($grow === 0) {
            $flat++;
        }
        $prev = $now;
    }
    echo "  → 连续不增长的轮数：" . $flat . " / 8（判据：后 4 轮都应为 0）" . PHP_EOL;
}

function caseTimerChurn(bool $withTimer): void
{
    echo PHP_EOL . "== [2] 带定时器的 spawn / kill（每轮 2000 个）"
        . ($withTimer ? "" : " —— 对照组：同样有 onStart 但不建定时器") . " ==" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();

    $warm = [];
    for ($i = 0; $i < 2000; $i++) {
        if ($withTimer) {
            $warm[] = $s->spawnOf(function (): Service {
                return new Ticker();
            });
        } else {
            $warm[] = $s->spawnOf(function (): Service {
                return new TickerNoTimer();
            });
        }
    }
    killBatch($s, $warm);
    unset($warm);
    $s->tick();

    $base = memory_get_usage(false);
    for ($r = 0; $r < 10; $r++) {
        $m0 = memory_get_usage(false);
        $addrs = [];
        for ($i = 0; $i < 2000; $i++) {
            if ($withTimer) {
                $addrs[] = $s->spawnOf(function (): Service {
                    return new Ticker();
                });
            } else {
                $addrs[] = $s->spawnOf(function (): Service {
                    return new TickerNoTimer();
                });
            }
        }
        $m1 = memory_get_usage(false);
        killBatch($s, $addrs);
        unset($addrs);
        $m2 = memory_get_usage(false);
        usleep(60000);
        $s->tick();
        $m3 = memory_get_usage(false);

        $st = $s->stats();
        echo "  第 " . ($r + 1) . " 轮：spawn +" . ($m1 - $m0)
            . "  kill " . ($m2 - $m1)
            . "  tick " . ($m3 - $m2)
            . "  净 +" . ($m3 - $m0) . PHP_EOL;
        echo "        " . $st["timer_detail"] . PHP_EOL;
    }
    echo "  → 定时器必须回到 0（kill 要撤销它名下的定时器，否则到点往死服务投消息）" . PHP_EOL;
    echo "  → 内存判据：后 3 轮净增应为 0" . PHP_EOL;
}

/** 循环引用的 GC 停顿 */
function caseGc(): void
{
    echo PHP_EOL . "== [3] 循环引用的 GC 停顿 ==" . PHP_EOL;
    // Service 之间不共享引用，但业务对象之间会有（玩家指向公会、公会持有成员）。
    // 这里量的是"清一次这样的垃圾要多久"——它决定垃圾堆积后会不会有周期性卡顿。
    $nodeCount = 20000;
    $base = memory_get_usage(false);
    /** @var array<int, \stdClass> */
    $nodes = [];
    for ($i = 0; $i < $nodeCount; $i++) {
        $o = new \stdClass();
        $o->self = $o;
        $nodes[] = $o;
    }
    $beforeGc = memory_get_usage(false);
    // 必须先把外部引用丢掉：对象还在 $nodes 里时 refcount > 0，
    // GC 会把它们当"活对象"，测出来就是"回收 0 个、释放 0 KB"
    unset($nodes);
    $t0 = hrtime(true);
    $collected = gc_collect_cycles();
    $gcUs = intdiv(hrtime(true) - $t0, 1000);
    $afterGc = memory_get_usage(false);

    echo "  " . $nodeCount . " 个自引用对象：GC 用了 " . $gcUs . " µs，回收 "
        . $collected . " 个，释放 " . intdiv($beforeGc - $afterGc, 1024) . " KB" . PHP_EOL;
    echo "  → 单个服务持有大量互指对象时，这一次停顿会落在触发 GC 的那条消息上" . PHP_EOL;
    echo "  → 对照：actor 模型下 Service 之间不共享引用，这类对象主要来自业务数据" . PHP_EOL;
}

function main(): void
{
    echo "== 生命周期稳定性 ==" . PHP_EOL;
    caseChurn();
    caseTimerChurn(true);
    caseTimerChurn(false);
    caseGc();
}
