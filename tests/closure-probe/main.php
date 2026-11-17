<?php

/**
 * 探测 AOT 下几个回调的闭包签名到底要几个参数。
 *
 * 用 MODE 环境变量选一个（每个模式单独跑一个进程，崩了不影响别的）：
 *   t0 / t1   Timer::tick 的闭包，0 参数 / 1 参数
 *   a0 / a1   Timer::after 的闭包
 *   c0 / c1   swoole_coroutine_create 的闭包
 *
 * 判据：哪个模式能打印出"回调执行"且正常退出，就说明那个签名是对的。
 */

function main(): void
{
    $mode = getenv("MODE") ?: "t0";
    echo "MODE=" . $mode . PHP_EOL;

    if ($mode === "t0") {
        \Swoole\Timer::tick(20, function (): void {
            echo "  回调执行（0 参数）" . PHP_EOL;
        });
    } elseif ($mode === "t1") {
        \Swoole\Timer::tick(20, function (int $id): void {
            echo "  回调执行（1 参数）id=" . $id . PHP_EOL;
        });
    } elseif ($mode === "a0") {
        \Swoole\Timer::after(50, function (): void {
            echo "  回调执行（0 参数）" . PHP_EOL;
        });
    } elseif ($mode === "a1") {
        \Swoole\Timer::after(50, function (int $id): void {
            echo "  回调执行（1 参数）id=" . $id . PHP_EOL;
        });
    } elseif ($mode === "c0") {
        \swoole_coroutine_create(function (): void {
            echo "  协程执行（0 参数）" . PHP_EOL;
        });
    } elseif ($mode === "c1") {
        \swoole_coroutine_create(function (int $cid): void {
            echo "  协程执行（1 参数）cid=" . $cid . PHP_EOL;
        });
    }

    // 500ms 后收尾。这个用 1 参数（ext-probe 里验证过 after 传 1 个能跑）
    \Swoole\Timer::after(500, function (int $id): void {
        echo "  [结束]" . PHP_EOL;
        exit(0);
    });
}
