<?php

/**
 * 协程化 Scheduler 的效果验证。
 *
 * 两个服务：
 *   A：每 200ms 做一次 redis PING（公网，RTT 约 31ms）
 *   B：每 10ms 打一个时间戳
 *
 * 关闭协程：A 在 tick 里同步跑，卡住整个事件循环 → B 的间隔出现 31ms 尖峰
 * 开启协程：A 挂起在自己协程里 → B 稳定在 10ms 附近
 *
 * 用 CORO=0/1 跑两次，同一份代码。
 */

use TSF\Service\Message;
use TSF\Service\Scheduler;
use TSF\Service\Service;

const MSG_PING = 8101;
const MSG_TICK = 8102;

/** A：故意慢的服务（公网 redis，RTT 约 31ms） */
final class SlowService extends Service
{
    /** @var \Redis|null */
    private $redis = null;

    public static int $pings = 0;

    public static float $totalMs = 0.0;

    public function onStart(): void
    {
        $r = new \Redis();
        try {
            $r->connect("47.92.229.255", 22379, 3.0);
            $r->auth("chuanqi");
        } catch (\Throwable $e) {
            $r = null;
        }
        $this->redis = $r;
    }

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== MSG_PING) {
            return;
        }
        if (!($this->redis instanceof \Redis)) {
            return;
        }
        $t0 = hrtime(true);
        $this->redis->ping();
        $d = (hrtime(true) - $t0) / 1e6;
        self::$totalMs += $d;
        self::$pings++;
    }
}

/** B：被打点的服务 */
final class TickService extends Service
{
    /** @var array<int, float> */
    public static array $stamps = [];

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== MSG_TICK) {
            return;
        }
        self::$stamps[] = microtime(true);
    }
}

function main(): void
{
    $mode = getenv("CORO") === "1";
    echo "模式: " . ($mode ? "协程" : "同步") . PHP_EOL;

    // 必须在创建任何协程之前开 hook
    \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    Scheduler::instance()->setCoroutine($mode);

    $slowAddr = Scheduler::instance()->spawnOf(function (): SlowService {
        return new SlowService();
    });
    $tickAddr = Scheduler::instance()->spawnOf(function (): TickService {
        return new TickService();
    });

    // 驱动调度器（和 WsServer 里的做法一致）
    \Swoole\Timer::tick(1, function (int $id): void {
        Scheduler::instance()->tick(0);
    });

    \Swoole\Timer::tick(200, function (int $id) use ($slowAddr): void {
        Scheduler::instance()->deliver($slowAddr, MSG_PING, "");
    });

    \Swoole\Timer::tick(10, function (int $id) use ($tickAddr): void {
        Scheduler::instance()->deliver($tickAddr, MSG_TICK, "");
    });

    \Swoole\Timer::after(3000, function () use ($mode): void {
        $stamps = TickService::$stamps;
        $n = count($stamps);
        echo "打点次数: " . $n . PHP_EOL;
        if ($n < 10) {
            echo "采样太少" . PHP_EOL;
            exit(1);
        }

        $gaps = [];
        $i = 1;
        while ($i < $n) {
            $g = ($stamps[$i] - $stamps[$i - 1]) * 1000.0;
            if ($g > 0.0 && $g < 200.0) {
                $gaps[] = $g;
            }
            $i++;
        }

        $sum = 0.0;
        $max = 0.0;
        $over20 = 0;
        foreach ($gaps as $g) {
            $sum += $g;
            if ($g > $max) {
                $max = $g;
            }
            if ($g > 20.0) {
                $over20++;
            }
        }
        $cnt = count($gaps);
        $avg = $cnt > 0 ? $sum / $cnt : 0.0;

        echo "平均间隔: " . round($avg, 2) . " ms" . PHP_EOL;
        echo "最大间隔: " . round($max, 2) . " ms" . PHP_EOL;
        echo "超过 20ms: " . $over20 . " / " . $cnt . PHP_EOL;

        $pings = SlowService::$pings;
        $avgMs = $pings > 0 ? SlowService::$totalMs / $pings : 0.0;
        echo "redis 调用: " . $pings . " 次，平均 " . round($avgMs, 1) . " ms" . PHP_EOL;

        echo PHP_EOL . "判定: ";
        if ($mode) {
            if ($max < 20.0) {
                echo "OK 协程生效（最大 " . round($max, 1) . "ms < 20ms）";
            } else {
                echo "FAIL 仍有尖峰 " . round($max, 1) . "ms";
            }
        } else {
            if ($max > 20.0) {
                echo "OK 符合预期（同步模式有 " . round($max, 1) . "ms 尖峰）";
            } else {
                echo "FAIL 没有尖峰，说明 redis 不够慢";
            }
        }
        echo PHP_EOL;
        exit(0);
    });
}
