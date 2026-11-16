<?php

use TSF\Transport\Server;

/**
 * fd 上限检查的测试。
 *
 * 两件事：
 *   1. 解析逻辑（构造各种 /proc/self/limits 文本）
 *   2. 真实数据交叉验证——把当前进程的 ulimit 与解析结果对比。
 *      ulimit 由 run.sh 写进 /tmp/fd_expect（AOT 下 getenv 不可用，只能走文件）。
 */

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;

    public static function ok(bool $cond, string $name, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $name . ($detail !== "" ? "   [" . $detail . "]" : "") . PHP_EOL;
            return;
        }
        self::$fail++;
        echo "  FAIL " . $name . "   " . $detail . PHP_EOL;
    }
}

function main(): void
{
    echo "== /proc/self/limits 解析 ==" . PHP_EOL;

    // 真实格式（列宽对齐，中间是大段空格）
    $sample = "Limit                     Soft Limit           Hard Limit           Units     \n"
        . "Max cpu time              unlimited            unlimited            seconds   \n"
        . "Max open files            10240                1048576              files     \n"
        . "Max locked memory         8388608              8388608              bytes     \n";

    T::ok(Server::parseMaxOpenFiles($sample) === 10240, "标准格式取软限制", "10240");

    $big = "Max open files            1048576              1048576              files     \n";
    T::ok(Server::parseMaxOpenFiles($big) === 1048576, "大数值", "1048576");

    $unlimited = "Max open files            unlimited            unlimited            files     \n";
    T::ok(Server::parseMaxOpenFiles($unlimited) === 0, "unlimited 解析为 0（不误判为 0 个 fd）");

    $none = "Max cpu time              unlimited            unlimited            seconds   \n";
    T::ok(Server::parseMaxOpenFiles($none) === 0, "没有该行返回 0");

    T::ok(Server::parseMaxOpenFiles("") === 0, "空输入返回 0");

    $truncated = "Max open files            \n";
    T::ok(Server::parseMaxOpenFiles($truncated) === 0, "只有前缀没有数字返回 0");

    // 行首没有换行（单行输入）
    $single = "Max open files            65535                65535                files     ";
    T::ok(Server::parseMaxOpenFiles($single) === 65535, "无结尾换行也能解析");

    echo PHP_EOL . "== 真实 /proc/self/limits ==" . PHP_EOL;

    $path = "/proc/self/limits";
    T::ok(is_readable($path), "/proc/self/limits 可读");

    $real = (string) file_get_contents($path);
    $parsed = Server::parseMaxOpenFiles($real);
    T::ok($parsed > 0, "真实数据能解析出软限制", "值=" . $parsed);

    if (is_readable("/tmp/fd_expect")) {
        $expect = (int) trim((string) file_get_contents("/tmp/fd_expect"));
        T::ok($parsed === $expect, "与 shell 的 ulimit -n 一致", "解析=" . $parsed . " ulimit=" . $expect);
    } else {
        echo "  SKIP 未提供 /tmp/fd_expect，跳过与 ulimit 的交叉验证" . PHP_EOL;
    }

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
}
