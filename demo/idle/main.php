<?php

use Gen\Proto\Codec;
use Gen\Proto\Msg;
use Idle\IdleService;
use TSF\Kernel\AppConfig;
use TSF\Kernel\Bootstrap;
use TSF\Kernel\Logger;
use TSF\Gateway\Gateway;
use TSF\Gateway\RateLimiter;
use TSF\Gateway\SessionManager;
use TSF\Gateway\TokenAuth;
use TSF\Protocol\Msg as CoreMsg;
use TSF\Protocol\Payload\RegistryPayload;
use TSF\Protocol\ProtocolStack;
use TSF\Proto\Message as ProtoMessage;
use TSF\Service\Scheduler;
use TSF\Transport\Server;
use TSF\Transport\ServerFactory;

/**
 * 挂机收菜 demo。
 *
 * 和 tsf new 的模板结构一致（六步），多两步：
 *   - buildProtocol 里注册 protobuf 的编解码（payload=protobuf 需要显式 register）
 *   - buildIdle 把业务服务拉起来，Gateway 的消息转发给它
 *
 * 这个 demo 想验证的东西（都是"零件级测试抓不到"的）：
 *   1. protobuf 链路：proto 生成 → Gateway 编解码 → Service 收到对象
 *   2. 状态归属：内存是缓存、db 是真相（断线不清内存，重连靠 lastTick 补收益）
 *   3. 跨组件协作：db（持久化）+ redis（排行榜）+ mail（离线补偿）
 */
function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $cfg = AppConfig::load();
    $proto = buildProtocol($cfg);
    $server = buildServer($cfg, $proto);
    $gw = buildGateway($cfg, $server, $proto);
    $idleAddr = buildIdle($gw);

    registerHandlers($gw, $idleAddr);
    registerDisconnect($gw, $idleAddr);
    $gw->boot();

    Logger::info("idle ready", [
        "host" => $cfg->host,
        "port" => $cfg->port,
        "payload" => $proto->payloadName(),
        "idle_addr" => $idleAddr,
    ]);
    $server->start();
}

/**
 * 协议栈 + protobuf 注册。
 *
 * `payload: protobuf` 不是"自动按 msgId 找类"——它需要显式 register 每个协议的
 * 编码器和解码器。生成的 `Codec` 知道怎么按 msgId 分发，这里把它接上。
 */
function buildProtocol(AppConfig $cfg): ProtocolStack
{
    $proto = ProtocolStack::fromConfig($cfg->frameName, $cfg->payloadName);
    if ($cfg->payloadName !== "protobuf" && $cfg->payloadName !== "registry") {
        return $proto;
    }
    $payload = $proto->payload();
    if (!($payload instanceof RegistryPayload)) {
        return $proto;
    }

    $ids = [
        Msg::LOGIN_REQ, Msg::LOGIN_RESP,
        Msg::COLLECT_REQ, Msg::COLLECT_RESP,
        Msg::RANK_REQ, Msg::RANK_RESP,
        Msg::MAIL_LIST_REQ, Msg::MAIL_LIST_RESP,
        Msg::MAIL_CLAIM_REQ, Msg::MAIL_CLAIM_RESP,
        Msg::ERROR_MSG,
    ];
    foreach ($ids as $one) {
        $mid = $one;
        $payload->register(
            $mid,
            function (string|array|object $m): string {
                if ($m instanceof ProtoMessage) {
                    return $m->encode();
                }
                return "";
            },
            function (string $body) use ($mid): string|array|object {
                $decoded = Codec::decode($mid, $body);
                if ($decoded === null) {
                    return "";
                }
                return $decoded;
            }
        );
    }
    return $proto;
}

function buildServer(AppConfig $cfg, ProtocolStack $proto): Server
{
    return ServerFactory::make(
        $cfg->transport,
        $cfg->host,
        $cfg->port,
        $proto->frame(),
        $cfg->serverOpts()
    );
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

function buildIdle(Gateway $gw): int
{
    // 回包通道注入：Service 不该直接依赖 Gateway，用闭包把"发到某个 fd"传进去。
    // 参照 demo/mmo-mini 的做法（它也是构造注入 \Closure $publish）。
    $publish = function (int $fd, int $msgId, string|array|object $data) use ($gw): void {
        $gw->send($fd, $msgId, $data);
    };
    return Scheduler::instance()->spawnOf(function () use ($publish): IdleService {
        return new IdleService($publish);
    });
}

/**
 * 把业务消息转发给 IdleService。
 *
 * 用 **session 参数传 fd**（`deliver($addr, $msgId, $data, $fd)`）而不是像
 * mmo-mini 那样 json_encode 包一层 —— 因为 payload=protobuf 时 $data 是
 * `Message` 对象，json_encode 会得到 `{}`，信息全丢。
 */
function registerHandlers(Gateway $gw, int $idleAddr): void
{
    $ids = [
        Msg::LOGIN_REQ,
        Msg::COLLECT_REQ,
        Msg::RANK_REQ,
        Msg::MAIL_LIST_REQ,
        Msg::MAIL_CLAIM_REQ,
    ];
    foreach ($ids as $one) {
        $mid = $one;
        $gw->on($mid, function (int $fd, int $uid, string|array|object $data) use ($idleAddr, $mid): void {
            Scheduler::instance()->deliver($idleAddr, $mid, $data, $fd);
        });
    }
}

/** 断线：投一条 msgId=0 的消息过去，让服务解绑 fd（内存状态保留） */
function registerDisconnect(Gateway $gw, int $idleAddr): void
{
    $gw->onDisconnect(function (int $fd, int $uid) use ($idleAddr): void {
        Scheduler::instance()->deliver($idleAddr, 0, "", $fd);
    });
}
