<?php

use TSF\Kernel\Bootstrap;
use TSF\Kernel\Logger;
use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Msg;
use TSF\Transport\Server;
use TSF\Transport\Tcp\TcpServer;
use TSF\Transport\Ws\WsServer;

/**
 * TLS 守卫服务端。环境变量：
 *   TSF_MODE  tcp | ws
 *   TSF_SSL   1 = 带证书启动；其它 = 明文
 *   TSF_HALF  1 = 只配证书不配私钥（配置错误的场景）
 *   TSF_CERT / TSF_KEY  证书和私钥路径
 *   TSF_PORT  端口
 */
function main(): void
{
    Bootstrap::boot(__DIR__, "app");
    $mode = (string) getenv("TSF_MODE");
    $port = (int) (string) getenv("TSF_PORT");
    $cert = (string) getenv("TSF_CERT");
    $key = (string) getenv("TSF_KEY");

    $options = ["max_conn" => 64];
    if (getenv("TSF_SSL") === "1") {
        $options["ssl_cert_file"] = $cert;
        if (getenv("TSF_HALF") !== "1") {
            $options["ssl_key_file"] = $key;
        }
    }

    $frame = new HeaderFrame();
    // 分开两个分支、各走各的变量：AOT 下变量的类型由第一次赋值决定，
    // 不能先赋 TcpServer 再赋 WsServer
    if ($mode === "ws") {
        $ws = new WsServer("127.0.0.1", $port, $frame, $options);
        serve($ws, $mode, $port);
    } else {
        $tcp = new TcpServer("127.0.0.1", $port, $frame, $options);
        serve($tcp, $mode, $port);
    }
}

function serve(Server $server, string $mode, int $port): void
{
    $server->onFrame(function (int $fd, int $msgId, string $body, int $flags) use ($server): void {
        $server->sendFrame($fd, Msg::ECHO_RESP, $body);
    });
    Logger::info("tls-guard ready", ["mode" => $mode, "port" => $port, "secure" => $server->isSecure()]);
    $server->start();
}
