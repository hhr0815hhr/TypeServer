<?php

/**
 * OOM 的时候，还有没有机会留下诊断信息？
 *
 * 背景：`Scheduler` 的 crash dump 只在 `catch (\Throwable)` 里生成。
 * OOM（Allowed memory size exhausted）是 **fatal error，不是 Throwable**，
 * 走不到那个 catch。如果 AOT 产物在 OOM 时直接 abort，线上表现就是
 * "服务莫名其妙重启了，日志里什么都没有"——最难查的一类。
 *
 * 这个实验回答三件事：
 *   1 `ini_set("memory_limit")` 在 AOT 产物里生不生效
 *   2 OOM 时 `register_shutdown_function` 会不会执行
 *   3 `error_get_last()` 能不能拿到错误内容
 *
 * 用 MODE 选哪一组，run.sh 分开跑（OOM 会把进程带走，不能连着测）。
 */

function tryAlloc(int $mb): void
{
    echo "  尝试分配 " . $mb . " MB（memory_limit=" . ini_get("memory_limit") . "）" . PHP_EOL;
    $s = str_repeat("x", $mb * 1048576);
    // 用一下，防止被优化掉
    echo "  分配成功，长度 " . strlen($s) . PHP_EOL;
}

function main(): void
{
    $mode = getenv("MODE");

    if ($mode === "shutdown") {
        echo "== 有没有 shutdown 钩子 ==" . PHP_EOL;
        register_shutdown_function(function (): void {
            $err = error_get_last();
            if ($err === null || $err === []) {
                echo "  shutdown 执行了，但 error_get_last 是空的" . PHP_EOL;
                return;
            }
            echo "  shutdown 执行了，error_get_last: type=" . $err["type"]
                . " msg=" . $err["message"] . PHP_EOL;
            file_put_contents("/tmp/oom_last.txt", json_encode($err));
        });
        // 必须先把上限压到 32M，否则 512M 下 100MB 根本不会 OOM
        ini_set("memory_limit", "32M");
        echo "  钩子已注册，memory_limit=" . ini_get("memory_limit") . PHP_EOL;
        tryAlloc(100);
        echo "  程序正常结束（说明没 OOM）" . PHP_EOL;
        return;
    }

    if ($mode === "limit") {
        echo "== ini_set 对 memory_limit 生不生效 ==" . PHP_EOL;
        echo "  改之前：" . ini_get("memory_limit") . PHP_EOL;
        ini_set("memory_limit", "32M");
        echo "  改之后：" . ini_get("memory_limit") . PHP_EOL;
        tryAlloc(16);
        echo "  16MB 分配成功 → 说明 limit 至少是 32M" . PHP_EOL;
        return;
    }

    echo "用 MODE=shutdown|limit" . PHP_EOL;
}
