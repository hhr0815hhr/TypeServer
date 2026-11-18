<?php

use TSF\Kernel\AppConfig;
use TSF\Kernel\Bootstrap;

/**
 * 可行性验证：tpc 能不能把 Swoole 的 HttpServer 编进产物。
 *
 * 只验证三件事：
 *   1. `Swoole\Http\Server` 的构造和回调能在 AOT 下通过
 *   2. `onRequest(Request, Response)` 的闭包参数类型能被编译
 *   3. 产物跑起来后 curl 能拿到响应
 *
 * 不验证路由、中间件、编解码 —— 那些只有确认这条路能走才值得做。
 */
function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $cfg = AppConfig::load();
    $host = "0.0.0.0";
    $port = 9601;

    $server = new \Swoole\Http\Server($host, $port);

    $server->on("request", function (\Swoole\Http\Request $req, \Swoole\Http\Response $res): void {
        $path = $req->server["request_uri"] ?? "/";
        if ($path === "/ping") {
            $res->header("Content-Type", "application/json");
            $res->end("{\"ok\":true,\"via\":\"aot+swoole-http\"}");
            return;
        }
        $res->status(404);
        $res->end("not found");
    });

    \TSF\Kernel\Logger::info("http probe listening", ["port" => $port]);
    $server->start();
}
