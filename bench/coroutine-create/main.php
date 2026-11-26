<?php

use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * 创建协程的成本拆解：11 µs/个 里，多少是 Swoole 本身，多少是我们自己的写法。
 *
 * ## 背景
 *
 * `bench/coroutine-scale` 测出 `Scheduler::tick()` 分发 5000 个协程要 56.5 ms
 * （约 11 µs/个）。而 Swoole 官方说协程创建在 1–2 µs 量级。差的那 9 µs 得找到出处。
 *
 * ## 四组，逐层加码
 *
 *   A 每次新建一个空闭包        —— 最朴素的写法
 *   B 每次新建一个捕获 5 个变量的闭包 —— `dispatchAsync` 的写法（use self/svc/box/id/budget）
 *   C 复用同一个闭包             —— 闭包只建一次
 *   D 复用 + 参数传递            —— 闭包建一次，每轮只传参
 *
 * 如果 D 明显快于 B，说明省下的是"闭包创建 + 捕获打包"，优化方向就是
 * 把闭包提到 dispatchAsync 外面、改成传参。
 */

const N = 5000;

final class Sink
{
    public int $hits = 0;

    public function noop(): void
    {
        $this->hits++;
    }
}

function bench(string $label, int $n, \Closure $body): void
{
    $t0 = hrtime(true);
    $body();
    $us = intdiv(hrtime(true) - $t0, 1000);
    echo "  " . str_pad($label, 30) . str_pad(number_format($us) . " µs", 14)
        . number_format($us / (float) $n, 2) . " µs/个" . PHP_EOL;
}

function main(): void
{
    Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

    \Swoole\Coroutine\run(function (): void {
        echo "== 协程创建成本拆解（每组 " . N . " 次）==" . PHP_EOL;

        // A：每次新建空闭包
        bench("A 新建空闭包", N, function (): void {
            for ($i = 0; $i < N; $i++) {
                Coroutine::create(function (): void {
                });
            }
        });

        // B：每次新建捕获 5 个变量的闭包（dispatchAsync 的形状）
        $sink = new Sink();
        $a = 1;
        $b = 2;
        $c = 3;
        $d = 4;
        $e = 5;
        bench("B 新建 5 捕获闭包", N, function () use ($sink, $a, $b, $c, $d, $e): void {
            for ($i = 0; $i < N; $i++) {
                Coroutine::create(function () use ($sink, $a, $b, $c, $d, $e): void {
                    $sink->noop();
                });
            }
        });

        // C：复用同一个闭包，不做任何事
        $fixed = function (): void {
        };
        bench("C 复用闭包（无捕获）", N, function () use ($fixed): void {
            for ($i = 0; $i < N; $i++) {
                Coroutine::create($fixed);
            }
        });

        // D：复用同一个闭包 + 参数传递
        $withArg = function (int $i, Sink $s): void {
            $s->noop();
        };
        bench("D 复用闭包 + 传参", N, function () use ($withArg, $sink): void {
            for ($i = 0; $i < N; $i++) {
                Coroutine::create($withArg, $i, $sink);
            }
        });

        echo PHP_EOL . "  → B 与 D 的差 = 闭包创建 + 捕获打包的代价" . PHP_EOL;
        echo "  → 若 D 接近 A，说明 dispatchAsync 的写法有优化空间" . PHP_EOL;

        // E：闭包体跑到第一次挂起就停（Sleeper 的真实形状）。
        // A~D 的协程体都是"立即结束"，而真实业务是"跑到 I/O 让出"——
        // 这中间隔着一次上下文切换，量级可能完全不同
        bench("E 闭包体跑到 usleep 挂起", N, function (): void {
            for ($i = 0; $i < N; $i++) {
                Coroutine::create(function (): void {
                    usleep(50000);
                });
            }
        });

        // E2：等上一轮全部结束、栈回池之后再跑一遍。
        // 如果比 E 快很多，说明成本大头是**栈的首次分配**而不是切换，
        // 那生产上"启动时预热协程池"就有明确收益
        Coroutine::sleep(0.15);
        bench("E2 同上，栈池已预热", N, function (): void {
            for ($i = 0; $i < N; $i++) {
                Coroutine::create(function (): void {
                    usleep(50000);
                });
            }
        });

        // F：在 E 的基础上多一次方法调用（模拟 onMessage 那层间接）
        $sink2 = new Sink();
        $slow = function (Sink $s): void {
            usleep(50000);
            $s->noop();
        };
        bench("F 复用闭包 + 方法调用后挂起", N, function () use ($slow, $sink2): void {
            for ($i = 0; $i < N; $i++) {
                Coroutine::create($slow, $sink2);
            }
        });
    });
}
