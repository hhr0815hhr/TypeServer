<?php

// 斗地主：WebSocket 服务端。
//
// 装配顺序：协议栈 → 传输 → Gateway（推送要用它）→ 牌桌 Service → 消息转发。
// 和 duel 的区别是这里只有一张桌（单桌 demo），所以不用 RoomManager。

use Ddz\DdzService;
use Ddz\Msg as DdzMsg;
use TSF\Gateway\Gateway;
use TSF\Gateway\RateLimiter;
use TSF\Gateway\SessionManager;
use TSF\Gateway\TokenAuth;
use TSF\Kernel\Bootstrap;
use TSF\Kernel\Config;
use TSF\Kernel\Logger;
use TSF\Protocol\ProtocolStack;
use TSF\Service\Scheduler;
use TSF\Transport\ServerFactory;

function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "doudizhu");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $host = Config::getString("server.host", "0.0.0.0");
    $port = Config::getInt("server.port", 9301);
    $heartbeat = Config::getInt("server.heartbeat_sec", 60);

    // 帧用原生实现；载荷用 json —— 浏览器端 JSON.stringify 直接对得上
    $proto = ProtocolStack::fromConfig(
        Config::getString("protocol.frame", "native"),
        Config::getString("protocol.payload", "json")
    );

    $server = ServerFactory::make(
        Config::getString("server.transport", "ws"),
        $host,
        $port,
        $proto->frame(),
        [
            "worker_num" => Config::getInt("server.worker_num", 1),
            "max_conn" => Config::getInt("server.max_conn", 200),
            "nodelay" => Config::getBool("server.nodelay", true),
        ]
    );

    $sessions = new SessionManager($heartbeat);
    $auth = new TokenAuth(
        Config::getMap("server.auth.tokens"),
        Config::getString("server.auth.secret", "")
    );
    $limiter = new RateLimiter(
        Config::getInt("server.rate_per_sec", 100),
        Config::getInt("server.rate_burst", 200)
    );

    $gw = new Gateway(
        $server,
        $sessions,
        $auth,
        $limiter,
        $heartbeat,
        1000,
        Config::getBool("server.auth.require", true),
        $proto->payload()
    );
    $gw->setSendFailLimit(50);

    // 牌桌服务：推送通过闭包回到 Gateway，Service 本身不依赖传输层
    $ddzAddr = Scheduler::instance()->spawnOf(function () use ($gw): DdzService {
        return new DdzService(function (int $fd, int $msgId, string|array|object $data) use ($gw): void {
            $gw->send($fd, $msgId, $data);
        });
    });

    // ---- 客户端消息 → 牌桌 ----
    // 统一带上 uid 与 fd：Service 需要知道"谁发的"和"回哪去"。
    // 用 JSON 字符串而不是数组，是因为 Message::$data 的类型是 string|array|object，
    // 而数组里再嵌 mixed 会踩全框架"不出现 mixed"的红线。
    $gw->on(DdzMsg::SEAT, function (int $fd, int $uid, string|array|object $data) use ($ddzAddr): void {
        Scheduler::instance()->deliver($ddzAddr, DdzMsg::SEAT, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->on(DdzMsg::READY, function (int $fd, int $uid, string|array|object $data) use ($ddzAddr): void {
        Scheduler::instance()->deliver($ddzAddr, DdzMsg::READY, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->on(DdzMsg::CALL, function (int $fd, int $uid, string|array|object $data) use ($ddzAddr): void {
        Scheduler::instance()->deliver($ddzAddr, DdzMsg::CALL, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->on(DdzMsg::PLAY, function (int $fd, int $uid, string|array|object $data) use ($ddzAddr): void {
        Scheduler::instance()->deliver($ddzAddr, DdzMsg::PLAY, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->on(DdzMsg::PASS, function (int $fd, int $uid, string|array|object $data) use ($ddzAddr): void {
        Scheduler::instance()->deliver($ddzAddr, DdzMsg::PASS, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->on(DdzMsg::LEAVE, function (int $fd, int $uid, string|array|object $data) use ($ddzAddr): void {
        Scheduler::instance()->deliver($ddzAddr, DdzMsg::LEAVE, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });

    // 断线清理：不处理的话，关掉标签页座位会一直被占，再开就是「桌子满了」
    //
    // 注意回调是 **(fd, uid) 两个参数** —— Gateway 里是 `$cb($fd, $uid)`。
    // 只声明一个参数在 PHP 里语法合法，但在 AOT 下参数个数不匹配会直接崩
    // （报 `stdClass->{closure}()`，编译期不报错）。有 uid 就不必按 fd 反查了。
    $gw->onDisconnect(function (int $fd, int $uid) use ($ddzAddr): void {
        Logger::info("client gone", ["fd" => $fd, "uid" => $uid]);
        Scheduler::instance()->deliver($ddzAddr, DdzMsg::LEAVE, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => [],
        ]));
    });

    $gw->boot();

    Logger::info("doudizhu ready", [
        "host" => $host,
        "port" => $port,
        "transport" => Config::getString("server.transport", "ws"),
        "payload" => $proto->payloadName(),
    ]);

    $server->start();
}
