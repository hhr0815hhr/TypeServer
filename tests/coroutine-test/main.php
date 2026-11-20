<?php

/**
 * 协程化 Scheduler 的效果验证。
 *
 * 两个服务：
 *   A：每 200ms 做一次 30ms 的阻塞调用（usleep 模拟慢 I/O）
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

/**
 * A：故意慢的服务——每次处理都做一次 30ms 的"阻塞调用"。
 *
 * 原来这里连的是作者环境里的一台公网 redis（RTT ~31ms）。换台机器就连不上，
 * 测试照样"通过"，但**一次都没真正验证**——和仓库里那些没接线的用例是一个毛病。
 *
 * 现在用 `usleep(30000)` 直接模拟。为什么它等价：`Runtime::enableCoroutine`
 * 的 `SWOOLE_HOOK_SLEEP` 会把 usleep 变成"协程让出"，同步模式下则实实在在卡住
 * 整个 worker。要验证的命题（"慢调用会不会阻塞其他服务"）完全一样，
 * 而且不依赖 redis 扩展装没装。
 *
 * 顺带一个 AOT 教训：原来写 `try { new \Redis() } catch (\Throwable $e) { return null; }`，
 * 扩展不存在时抛的是 `Error`，**catch 没接住**（和 PDOException 那次一样），
 * 服务直接崩了、PING 计数永远是 0。AOT 下不要指望 catch 兜住"类不存在"。
 */
final class SlowService extends Service
{
    public static int $pings = 0;

    public static float $totalMs = 0.0;

    public function onMessage(Message $m): void
    {
        if ($m->msgId !== MSG_PING) {
            return;
        }
        $t0 = hrtime(true);
        usleep(30000);              // 模拟 30ms 的阻塞 I/O
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
    // **只在协程模式开 hook**。原来两档都开，导致"同步模式"里的 usleep 也被
    // hook 成协程让出，测不出尖峰——那样对比的是"有 hook 但单协程"和"有 hook 多协程"，
    // 而不是"阻塞"和"不阻塞"。
    if ($mode) {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    }
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
        echo "慢调用: " . $pings . " 次，平均 " . round($avgMs, 1) . " ms" . PHP_EOL;

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
                echo "FAIL 没有尖峰，说明慢调用不够慢";
            }
        }
        echo PHP_EOL;
        exit(0);
    });
}
