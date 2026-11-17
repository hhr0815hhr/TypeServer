<?php

// mmo-mini：一个房间的实时世界（帧循环 + AOI 视野 + 增量同步）

use Mmo\MmoService;
use Mmo\Msg as MmoMsg;
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
    Bootstrap::boot($base, "mmo-mini");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $host = Config::getString("server.host", "0.0.0.0");
    $port = Config::getInt("server.port", 9401);
    $heartbeat = Config::getInt("server.heartbeat_sec", 60);

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

    // 世界服务。onStart() 由 Scheduler::spawn 调用，帧循环在那里启动。
    $worldAddr = Scheduler::instance()->spawnOf(function () use ($gw): MmoService {
        return new MmoService(
            function (int $fd, int $msgId, string|array|object $data) use ($gw): void {
                $gw->send($fd, $msgId, $data);
            },
            Config::getInt("game.map_w", 2000),
            Config::getInt("game.map_h", 2000),
            Config::getInt("game.cell", 200),
            Config::getInt("game.speed", 6),
            Config::getInt("game.frame_ms", 50)
        );
    });

    $gw->on(MmoMsg::JOIN, function (int $fd, int $uid, string|array|object $data) use ($worldAddr): void {
        Scheduler::instance()->deliver($worldAddr, MmoMsg::JOIN, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->on(MmoMsg::MOVE, function (int $fd, int $uid, string|array|object $data) use ($worldAddr): void {
        Scheduler::instance()->deliver($worldAddr, MmoMsg::MOVE, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->on(MmoMsg::LEAVE, function (int $fd, int $uid, string|array|object $data) use ($worldAddr): void {
        Scheduler::instance()->deliver($worldAddr, MmoMsg::LEAVE, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });

    // 断线 = 离开世界（回调必须是 (fd, uid) 两参数）
    $gw->onDisconnect(function (int $fd, int $uid) use ($worldAddr): void {
        Scheduler::instance()->deliver($worldAddr, MmoMsg::LEAVE, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => [],
        ]));
    });

    $gw->boot();

    Logger::info("mmo-mini ready", [
        "host" => $host,
        "port" => $port,
        "frame_ms" => Config::getInt("game.frame_ms", 50),
        "cell" => Config::getInt("game.cell", 200),
    ]);

    $server->start();
}
