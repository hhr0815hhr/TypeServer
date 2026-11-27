<?php

use Ddz\App\LobbyService;
use Ddz\Protocol\Cmd;
use Ddz\Protocol\LobbyTask;
use TSF\Gateway\Gateway;
use TSF\Gateway\RateLimiter;
use TSF\Gateway\SessionManager;
use TSF\Gateway\TokenAuth;
use TSF\Kernel\AppConfig;
use TSF\Kernel\Bootstrap;
use TSF\Kernel\Logger;
use TSF\Protocol\ProtocolStack;
use TSF\Service\Scheduler;
use TSF\Service\Service;
use TSF\Transport\Server;
use TSF\Transport\ServerFactory;

/**
 * 斗地主（大厅 + 房间列表 + 房卡）。
 *
 *   Gateway ──▶ 大厅（房卡 / 房间列表 / 建房）
 *      │              └── spawn ──▶ 房间（一房一 Service，跑对局）
 *      └──────────────────────────▶ 推送回客户端
 *
 * 所有网络消息先进大厅，由它按 uid 决定转给哪个房间 —— main.php 不知道
 * "谁在哪个房间"，那份映射只有大厅维护，不存第二份。
 *
 * ## 和 demo/doudizhu 的区别
 *
 * 那个是"最小可玩版"：单文件装配 + Web 前端 + DB 存档，适合照着改。
 * 这个是"分层版"：Domain（纯规则，53 条单测）/ App（大厅、房间）/ Protocol 分开，
 * 适合看结构。两个都能跑，选哪个取决于你要参考什么。
 *
 * ## 简化（不影响玩法正确性）
 *
 * - 房卡存在大厅的进程内 map，没落库。真实项目应在 PlayerService + Repo。
 * - 没有"叫地主"：座位 0（房主）直接当地主拿底牌。
 * - 没有"准备"：三人齐了自动开局。
 */
function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $cfg = AppConfig::load();
    $proto = ProtocolStack::fromConfig($cfg->frameName, $cfg->payloadName);
    $server = ServerFactory::make(
        $cfg->transport,
        $cfg->host,
        $cfg->port,
        $proto->frame(),
        $cfg->serverOpts()
    );

    $gw = buildGateway($cfg, $server, $proto);
    $lobby = Scheduler::instance()->spawnOf(function () use ($gw): Service {
        return new LobbyService($gw);
    });
    registerHandlers($gw, $lobby);

    // 断线：转给大厅，由它转给玩家所在的房间（座位保留 30 秒等重连）
    $gw->onDisconnect(function (int $fd, int $uid) use ($lobby): void {
        postLobby($lobby, 11, $fd, $uid, 0, 1, []);
    });

    $gw->boot();

    Logger::info("ddz-hall ready", [
        "host" => $cfg->host,
        "port" => $cfg->port,
        "transport" => $cfg->transport,
        "frame" => $proto->frameName(),
        "payload" => $proto->payloadName(),
    ]);
    $server->start();
}

function buildGateway(AppConfig $cfg, Server $server, ProtocolStack $proto): Gateway
{
    $sessions = new SessionManager($cfg->heartbeatSec);
    $auth = new TokenAuth($cfg->authTokens, $cfg->authSecret);
    $limiter = new RateLimiter($cfg->ratePerSec, $cfg->rateBurst);

    $codec = null;
    if ($proto->payloadName() !== "raw") {
        $codec = $proto->payload();
    }

    $gw = new Gateway(
        $server,
        $sessions,
        $auth,
        $limiter,
        $cfg->heartbeatSec,
        1000,
        $cfg->requireAuth,
        $codec
    );
    $gw->setSendFailLimit($cfg->sendFailLimit);
    return $gw;
}

/**
 * 网络消息 → 内部消息。handler 只解析参数和投递，不做业务判断。
 */
function registerHandlers(Gateway $gw, int $lobby): void
{
    $gw->on(Cmd::LIST_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        postLobby($lobby, 4, $fd, $uid, 0, 1, []);
    });

    // 建房：{"level": 1|2|3}
    $gw->on(Cmd::CREATE_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        $level = 1;
        if (is_string($body)) {
            $d = json_decode($body, true);
            if (is_array($d) && isset($d["level"])) {
                $level = (int) $d["level"];
            }
        }
        postLobby($lobby, 1, $fd, $uid, 0, $level, []);
    });

    // 加入：{"roomId": n}
    $gw->on(Cmd::JOIN_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        $roomId = 0;
        if (is_string($body)) {
            $d = json_decode($body, true);
            if (is_array($d) && isset($d["roomId"])) {
                $roomId = (int) $d["roomId"];
            }
        }
        postLobby($lobby, 2, $fd, $uid, $roomId, 1, []);
    });

    $gw->on(Cmd::LEAVE_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        postLobby($lobby, 3, $fd, $uid, 0, 1, []);
    });

    // 出牌：{"cards": [id, ...]}
    $gw->on(Cmd::PLAY_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        $cards = [];
        if (is_string($body)) {
            $d = json_decode($body, true);
            if (is_array($d) && isset($d["cards"]) && is_array($d["cards"])) {
                foreach ($d["cards"] as $c) {
                    $cards[] = (int) $c;
                }
            }
        }
        postLobby($lobby, 5, $fd, $uid, 0, 1, $cards);
    });

    // 过牌
    $gw->on(Cmd::PASS_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        postLobby($lobby, 6, $fd, $uid, 0, 1, []);
    });

    // 准备
    $gw->on(Cmd::READY_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        postLobby($lobby, 7, $fd, $uid, 0, 1, []);
    });

    // 房主开始
    $gw->on(Cmd::START_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        postLobby($lobby, 8, $fd, $uid, 0, 1, []);
    });

    // 叫分：{"score": 0..3}
    $gw->on(Cmd::BID_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        $score = 0;
        if (is_string($body)) {
            $d = json_decode($body, true);
            if (is_array($d) && isset($d["score"])) {
                $score = (int) $d["score"];
            }
        }
        postLobby($lobby, 9, $fd, $uid, 0, 1, [], $score);
    });

    // 再来一局（房主）
    $gw->on(Cmd::AGAIN_REQ, function (int $fd, int $uid, string|array|object $body) use ($lobby): void {
        postLobby($lobby, 10, $fd, $uid, 0, 1, []);
    });
}

/**
 * 统一投递入口。
 *
 * 六个 handler 长得几乎一样，抽成一个函数：少写五份就少五处能写错的地方
 * （少传一个字段、op 写错），而且改内部消息结构时只改一处。
 *
 * @param int[] $cards
 */
function postLobby(int $lobby, int $op, int $fd, int $uid, int $roomId, int $level, array $cards, int $score = 0): void
{
    $t = new LobbyTask();
    $t->op = $op;
    $t->fd = $fd;
    $t->uid = $uid;
    $t->roomId = $roomId;
    $t->level = $level;
    $t->cards = $cards;
    $t->score = $score;
    Scheduler::instance()->deliverAs(0, $lobby, Cmd::SVC_LOBBY, $t);
}
