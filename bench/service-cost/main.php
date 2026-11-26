<?php

/**
 * Service 的固定开销：一条房间/场景"跑起来"要付多少底价。
 *
 * ## 要回答的问题
 *
 * "房卡房该一房一 Service，还是多房共享一个宿主"——这个决定取决于**每个 Service 的
 * 固定成本**，而不是房间逻辑本身。所以这里建一堆**什么都不做**的 Service，
 * 只测三件事：
 *
 *   1. 建 N 个 Service 要多少内存（每个多少字节）
 *   2. 每次 tick（一轮调度）要多少时间
 *   3. 加定时器之后涨多少
 *
 * 房间逻辑的成本另算（bench/scene-scale 测过）。这里只量"载体"本身。
 *
 * ## 数字怎么读
 *
 * **内存类稳定**（307–333 B，两轮相隔三天一致），可以跨天引用。
 * **时间类不行**：2026-11-23 测 0.79 µs/个，11-26 在**同一台机器**上测 1.8–2.3 µs。
 * 用 `bench/service`（同一段代码、11-23 也测过）做对照，它同样从 7.9 ms 涨到 18 ms、
 * 消息吞吐从 115 万降到 85 万 msg/s——是整机变慢（此前连跑了十几轮全量回归），
 * 不是代码退化。报"快了多少"必须带同批对照，见 `bench/REPORT.md` §二。
 */

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

/** 空服务：不处理任何消息，只占一个邮箱 + 一个注册项 */
final class Idle extends Service
{
    public int $hits = 0;

    public function onMessage(Message $m): void
    {
        $this->hits++;
    }
}

/** 带一个 60ms 定时器的服务（模拟房间的帧循环） */
final class Ticker extends Service
{
    public int $hits = 0;

    public function onStart(): void
    {
        $this->interval(60, 1);
    }

    public function onMessage(Message $m): void
    {
        $this->hits++;
    }
}

function mb(int $bytes): string
{
    return number_format($bytes / 1048576, 2) . " MB";
}

function nowUs(): int
{
    return intdiv(hrtime(true), 1000);
}

function caseIdle(): void
{
    echo PHP_EOL . "== [1] 空 Service 的固定成本 ==" . PHP_EOL;
    echo "  两种建法差在闭包：spawn(注册名) 复用工厂，spawnOf(闭包) 每次新建一个。" . PHP_EOL;
    foreach ([1000, 5000, 10000] as $n) {
        // 注册工厂：闭包只创建一次
        Scheduler::reset();
        $s = Scheduler::instance();
        $s->register("idle", function (): Service {
            return new Idle();
        });
        $before = memory_get_usage(false);

        $t0 = nowUs();
        for ($i = 0; $i < $n; $i++) {
            $s->spawn("idle");
        }
        $spawnRegUs = nowUs() - $t0;

        $t1 = nowUs();
        for ($r = 0; $r < 10; $r++) {
            $s->tick();
        }
        $tickUs = intdiv(nowUs() - $t1, 10);
        $used = memory_get_usage(false) - $before;

        // 每次新闭包
        Scheduler::reset();
        $s2 = Scheduler::instance();
        $t2 = nowUs();
        for ($j = 0; $j < $n; $j++) {
            $s2->spawnOf(function (): Service {
                return new Idle();
            });
        }
        $spawnClosureUs = nowUs() - $t2;

        echo "  " . str_pad((string) $n, 6, " ", STR_PAD_LEFT) . " 个: 内存 "
            . str_pad(mb($used), 10) . " （每个 " . number_format($used / (float) $n, 0) . " B）"
            . "   注册工厂 " . number_format($spawnRegUs / (float) $n, 2) . " µs/个"
            . "   每次新闭包 " . number_format($spawnClosureUs / (float) $n, 2) . " µs/个"
            . "   空 tick " . $tickUs . " µs" . PHP_EOL;
    }
}

function caseTicker(): void
{
    echo PHP_EOL . "== [2] 带帧定时器（每 60ms 一次）==" . PHP_EOL;
    foreach ([1000, 5000] as $n) {
        Scheduler::reset();
        $s = Scheduler::instance();
        $before = memory_get_usage(false);

        for ($i = 0; $i < $n; $i++) {
            $s->spawnOf(function () {
                return new Ticker();
            });
        }
        $used = memory_get_usage(false) - $before;

        // 推进几轮让定时器都注册上
        for ($r = 0; $r < 5; $r++) {
            $s->tick();
            usleep(1000);
        }

        $st = $s->stats();
        echo "  " . str_pad((string) $n, 6, " ", STR_PAD_LEFT) . " 个: 内存 "
            . str_pad(mb($used), 10) . " （每个 " . number_format($used / (float) $n, 0) . " B）"
            . "   定时器 " . ($st["timers"] ?? "?") . PHP_EOL;
    }
}

function caseFanout(): void
{
    echo PHP_EOL . "== [3] 投递扇出（1 个服务发给 N 个服务）==" . PHP_EOL;
    Scheduler::reset();
    $s = Scheduler::instance();

    $ids = [];
    for ($i = 0; $i < 2000; $i++) {
        $ids[] = $s->spawnOf(function () {
            return new Idle();
        });
    }
    $src = $s->spawnOf(function () {
        return new Idle();
    });

    $t0 = nowUs();
    for ($i = 0; $i < 2000; $i++) {
        $s->deliver($ids[$i], 1, "x");
    }
    $sendUs = nowUs() - $t0;
    $s->tick();

    echo "  2000 次投递: " . number_format($sendUs, 0) . " µs   平均 "
        . number_format($sendUs / 2000.0, 2) . " µs/条" . PHP_EOL;
    echo "  （这只是入队，实际处理发生在 tick 里）" . PHP_EOL;
}

function main(): void
{
    echo "== Service 载体成本 ==" . PHP_EOL;
    caseIdle();
    caseTicker();
    caseFanout();

    echo PHP_EOL . "== 怎么读 ==" . PHP_EOL;
    echo "  '每个 B' 是 Service 的底价：信箱、注册表项、对象头。" . PHP_EOL;
    echo "  房间自己的状态（玩家表、牌堆）要另算，那部分和用什么载体无关。" . PHP_EOL;
}
