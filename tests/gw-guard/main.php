<?php

use TSF\Gateway\Gateway;
use TSF\Gateway\RateLimiter;
use TSF\Gateway\SessionManager;
use TSF\Gateway\TokenAuth;
use TSF\Kernel\Bootstrap;
use TSF\Kernel\Logger;
use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Msg;
use TSF\Transport\Tcp\TcpServer;

/**
 * 网关守卫：业务 handler 抛异常时，影响面必须止于"这一帧"。
 *
 * 2001 = 一进 handler 就抛；1001 = 普通 echo。
 * 断言在 client.php（解释执行）里，这里只提供服务端。
 */
function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    $frame = new HeaderFrame();
    $server = new TcpServer("127.0.0.1", 9411, $frame, ["max_conn" => 64]);
    $sessions = new SessionManager(120);
    $auth = new TokenAuth(["tok-1" => 1, "tok-2" => 2]);
    $limiter = new RateLimiter(0, 0);
    $gw = new Gateway($server, $sessions, $auth, $limiter, 120, 1000, true, null);
    $gw->on(2001, function (int $fd, int $uid, string $body): void {
        throw new \RuntimeException("boom fd=" . $fd . " body=" . $body);
    });
    $gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, string $body) use ($gw): void {
        $gw->send($fd, Msg::ECHO_RESP, $body);
    });
    $gw->boot();
    Logger::info("gw-guard ready");
    $server->start();
}
