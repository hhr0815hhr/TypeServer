<?php

/**
 * 探测 AOT 下能不能用 UDS：
 *   1. stream_socket_server / client（原生 stream）
 *   2. stream_select
 *   3. 非阻塞读写
 *   4. Swoole\Client 是否可用
 *
 * 结果决定 Cluster 用什么做传输。
 */

function main(): void
{
    $path = "/tmp/tsf_probe_" . getmypid() . ".sock";
    @unlink($path);

    echo "== 能力 ==" . PHP_EOL;
    echo "  stream_socket_server: " . (function_exists("stream_socket_server") ? "yes" : "no") . PHP_EOL;
    echo "  stream_socket_client: " . (function_exists("stream_socket_client") ? "yes" : "no") . PHP_EOL;
    echo "  stream_select:        " . (function_exists("stream_select") ? "yes" : "no") . PHP_EOL;
    echo "  Swoole\\Client:        " . (class_exists("Swoole\\Client") ? "yes" : "no") . PHP_EOL;
    echo "  Swoole\\Event:         " . (class_exists("Swoole\\Event") ? "yes" : "no") . PHP_EOL;

    // 服务端
    $srv = @stream_socket_server("unix://" . $path, $errno, $errstr);
    if ($srv === false) {
        echo "  监听失败: " . $errstr . PHP_EOL;
        return;
    }
    echo "  监听成功 " . $path . PHP_EOL;

    // 客户端
    $cli = @stream_socket_client("unix://" . $path, $errno2, $errstr2, 2.0);
    if ($cli === false) {
        echo "  连接失败: " . $errstr2 . PHP_EOL;
        return;
    }
    echo "  连接成功" . PHP_EOL;

    stream_set_blocking($cli, false);
    stream_set_blocking($srv, false);

    // 等 accept
    $r = [$srv];
    $w = null;
    $e = null;
    $n = stream_select($r, $w, $e, 0, 200000);
    echo "  select(server) 返回 " . var_export($n, true) . PHP_EOL;
    $conn = @stream_socket_accept($srv, 0);
    if ($conn === false) {
        echo "  accept 失败" . PHP_EOL;
        return;
    }
    echo "  accept 成功" . PHP_EOL;
    stream_set_blocking($conn, false);

    // 写 → 读
    $msg = "hello-uds-" . str_repeat("x", 100);
    $wn = fwrite($cli, $msg);
    echo "  fwrite 返回 " . var_export($wn, true) . PHP_EOL;

    $r2 = [$conn];
    $w2 = null;
    $e2 = null;
    $n2 = stream_select($r2, $w2, $e2, 0, 200000);
    echo "  select(conn) 返回 " . var_export($n2, true) . PHP_EOL;
    $got = fread($conn, 8192);
    echo "  fread 长度 " . strlen((string) $got) . "，内容匹配 " . ($got === $msg ? "yes" : "no") . PHP_EOL;

    // 回写
    $wn2 = fwrite($conn, "pong");
    echo "  fwrite(conn) 返回 " . var_export($wn2, true) . PHP_EOL;
    $r3 = [$cli];
    $w3 = null;
    $e3 = null;
    stream_select($r3, $w3, $e3, 0, 200000);
    $got2 = fread($cli, 8192);
    echo "  fread(cli) = " . var_export($got2, true) . PHP_EOL;

    fclose($cli);
    fclose($conn);
    fclose($srv);
    @unlink($path);
    echo "完成" . PHP_EOL;
}
