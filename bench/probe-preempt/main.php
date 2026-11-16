<?php

use Swoole\Coroutine;

/** 纯 CPU 忙等，不主动让出 */
function busy(int $ms): int
{
    $t = hrtime(true);
    $limit = $ms * 1000000;
    $n = 0;
    while (hrtime(true) - $t < $limit) {
        $n++;
    }
    return $n;
}

function main(): void
{
    echo "== 能力 ==" . PHP_EOL;
    echo "  Coroutine\\run:      " . (function_exists("Swoole\\Coroutine\\run") ? "yes" : "no") . PHP_EOL;
    echo "  Coroutine::create:  " . (method_exists("Swoole\\Coroutine", "create") ? "yes" : "no") . PHP_EOL;
    echo "  Coroutine::cancel:  " . (method_exists("Swoole\\Coroutine", "cancel") ? "yes" : "no") . PHP_EOL;
    echo "  Coroutine::isCanceled: " . (method_exists("Swoole\\Coroutine", "isCanceled") ? "yes" : "no") . PHP_EOL;
    echo "  Timer::after:       " . (class_exists("Swoole\\Timer") ? "yes" : "no") . PHP_EOL;

    // 开启抢占式调度（若支持）
    $set = @Coroutine::set([
        "enable_preemptive_scheduler" => true,
        "max_preempt_count" => 10000,
    ]);
    echo "  set(preemptive):    " . ($set === false ? "不支持/失败" : "ok") . PHP_EOL;
    echo PHP_EOL;

    \Swoole\Coroutine\run(function (): void {
        // A：忙 120ms，全程不让出
        // B：每 2ms 累加一次，看它在 A 执行期间能不能被调度
        $ticks = 0;
        $done = false;

        $cidA = Coroutine::create(function () use (&$done): void {
            busy(120);
            $done = true;
        });

        Coroutine::create(function () use (&$ticks, &$done): void {
            while (!$done) {
                Coroutine::sleep(0.002);
                $ticks++;
            }
        });

        $t0 = hrtime(true);
        while (!$done) {
            Coroutine::sleep(0.001);
        }
        $elapsed = (hrtime(true) - $t0) / 1e6;

        echo "== 抢占测试 ==" . PHP_EOL;
        echo "  A 忙等时长      " . round($elapsed) . " ms" . PHP_EOL;
        echo "  B 在期间被调度  " . $ticks . " 次" . PHP_EOL;
        echo "  结论            " . ($ticks > 3 ? "A 执行期间 B 仍能跑（有抢占/让出）" : "B 被饿住（无抢占，纯 CPU 循环不可中断）") . PHP_EOL;
        echo PHP_EOL;

        // 外部 cancel 能否打断忙等
        $cancelled = false;
        $cidB = Coroutine::create(function () use (&$cancelled): void {
            busy(150);
            $cancelled = true;
        });

        if (method_exists("Swoole\\Coroutine", "cancel")) {
            Coroutine::create(function () use ($cidB): void {
                Coroutine::sleep(0.03);
                $ok = @Coroutine::cancel($cidB);
                echo "  cancel() 返回   " . ($ok === false ? "false（不支持或有协程在跑）" : "true") . PHP_EOL;
            });
        } else {
            echo "  cancel()       不存在" . PHP_EOL;
        }

        $t1 = hrtime(true);
        while (hrtime(true) - $t1 < 200 * 1000000) {
            Coroutine::sleep(0.005);
            if (Coroutine::getCid() < 0) {
                break;
            }
        }
        echo "  忙等是否被打断  " . ($cancelled ? "否（跑完了）" : "无法判定") . PHP_EOL;
        if (method_exists("Swoole\\Coroutine", "isCanceled")) {
            echo "  isCanceled(cid) " . var_export(Coroutine::isCanceled($cidB), true) . PHP_EOL;
        }
    });

    echo PHP_EOL . "完成" . PHP_EOL;
}
