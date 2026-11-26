<?php

use TSF\Kernel\Bootstrap;
use TSF\Kernel\Logger;
use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Msg;
use TSF\Transport\Tcp\TcpServer;

/**
 * 积压续处理：一条连接一次灌很多帧，服务端必须自己把积压消化完，
 * 不能等客户端"再发点什么"来催。
 *
 * Server::feed() 每次最多处理 1000 帧就得让位（防一条连接饿死其它连接）。
 * 断言在 client.php 里，这里只提供最朴素的 echo 服务。
 */
function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    $frame = new HeaderFrame();
    $server = new TcpServer("127.0.0.1", 9414, $frame, ["max_conn" => 64]);
    $server->onFrame(function (int $fd, int $msgId, string $body, int $flags) use ($server): void {
        $server->sendFrame($fd, Msg::ECHO_RESP, $body);
    });
    Logger::info("feed-resume ready");
    $server->start();
}
