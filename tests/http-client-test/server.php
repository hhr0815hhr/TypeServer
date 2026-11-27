<?php

/**
 * 测试用的目标服务（`php -S 127.0.0.1:9633 server.php`）。
 *
 * 只要三个端点：正常、回显（看 method/头/body）、慢响应（看超时）。
 */

$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);

if ($path === "/ok") {
    header("Content-Type: text/plain");
    echo "hello";
    return;
}

if ($path === "/echo") {
    header("Content-Type: text/plain");
    echo "method=" . ($_SERVER["REQUEST_METHOD"] ?? "") . "\n";
    echo "trace=" . ($_SERVER["HTTP_X_TRACE"] ?? "-") . "\n";
    echo "body=" . file_get_contents("php://input") . "\n";
    return;
}

if ($path === "/slow") {
    usleep(1500000);
    echo "slow";
    return;
}

http_response_code(404);
echo "not found";
