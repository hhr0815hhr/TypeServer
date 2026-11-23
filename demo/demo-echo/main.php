<?php

use TSF\Kernel\Bootstrap;
use TSF\Kernel\Config;
use TSF\Kernel\Logger;
use TSF\Gateway\Gateway;
use TSF\Gateway\RateLimiter;
use TSF\Gateway\SessionManager;
use TSF\Gateway\TokenAuth;
use TSF\Protocol\Contract\PayloadCodec;
use TSF\Protocol\Msg;
use TSF\Protocol\ProtocolStack;
use TSF\Transport\ServerFactory;

/**
 * actor 模式的回包服务：验证真实业务路径（feed → deliver → tick → onMessage → send）。
 *
 * echo 直连测的是"传输层"，而游戏服的每一帧都要走 Actor：投递、调度、处理、回包。
 * 这两个数字差多少，决定"批量写"对真实业务有没有用。
 */
final class EchoService extends \TSF\Service\Service
{
    private \TSF\Transport\Server $server;

    public function __construct(\TSF\Transport\Server $server)
    {
        $this->server = $server;
    }

    public function onMessage(\TSF\Service\Message $m): void
    {
        // session 里带的是 fd（onFrame 侧塞进来的）
        $body = is_string($m->data) ? $m->data : "";
        $this->server->sendFrame($m->session, Msg::ECHO_RESP, $body);
    }
}

function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $host = Config::getString("server.host", "0.0.0.0");
    $port = Config::getInt("server.port", 9001);

    $opts = [
        "worker_num" => Config::getInt("server.worker_num", 1),
        "max_conn" => Config::getInt("server.max_conn", 10000),
        "nodelay" => Config::getBool("server.nodelay", true),
    ];

    // ---- 协议：分帧 + 消息体，两层都可换（配置即可，不改代码）----
    // frame:   native（C++，推荐）| header（纯 PHP，排障用）
    // payload: raw（透传，默认）| json | msgpack | protobuf | registry
    $proto = ProtocolStack::fromConfig(
        Config::getString("protocol.frame", "native"),
        Config::getString("protocol.payload", "raw")
    );

    // payload 为 raw 时不往 Gateway 传编解码器——省掉每帧一次空转
    $payloadCodec = null;
    if ($proto->payloadName() !== "raw") {
        $payloadCodec = $proto->payload();
    }

    // 传输层：tcp（默认）或 ws。
    // 走工厂是为了 AOT 类型稳定：局部变量类型由首次赋值推断，
    // 在 if/else 里分别赋 TcpServer / WsServer 会报 "Cannot re-assign typed object"。
    $server = ServerFactory::make(
        Config::getString("server.transport", "tcp"),
        $host,
        $port,
        $proto->frame(),
        $opts
    );

    // gateway=off 只用于压测对照：跳过会话/限流/心跳，直连 onFrame。
    // 生产不要关——那样就没有鉴权、限流和空闲回收了。
    if (!Config::getBool("server.gateway", true)) {
        Logger::warn("gateway disabled (benchmark mode only)");
        // TSF_ECHO_MODE=drop 只收不回：用来把"读+拆包"和"编码+写回"的成本分开。
        // 这是性能定位的减法实验，不是功能入口。
        // 分帧采样：每 50 万帧打一次各段均值，用来定位 6.5 µs 的去向。
        // 注意不能在这里用 Swoole\Timer：那会提前创建事件循环，导致
        // "event-loop has already been created, unable to start Server"。
        // 批量发送：pipeline 下把一次 feed 的回包合并成一次 write
        if (Config::getBool("server.batch_send", false)) {
            $server->enableBatchSend(true);
        }
        $profOn = Config::getBool("server.profile", false);
        if ($profOn) {
            \TSF\Transport\Server::setProfile(true);
        }
        $echoMode = getenv("TSF_ECHO_MODE");
        $dropOnly = is_string($echoMode) && $echoMode === "drop";
        $actorMode = is_string($echoMode) && $echoMode === "actor";
        if ($actorMode) {
            // 真实业务路径：帧 → deliver → tick 里 onMessage → sendFrame。
            // 批窗口钩子让 tick 期间的回包也合并（否则业务路径享受不到批量写）。
            $sched = \TSF\Service\Scheduler::instance();
            $echoAddr = $sched->spawnOf(function () use ($server): EchoService {
                return new EchoService($server);
            });
            $sched->onBatchBegin(function () use ($server): void {
                $server->beginBatch();
            });
            $sched->onBatchEnd(function () use ($server): void {
                $server->endBatch();
            });
            $server->onFrame(function (int $fd, int $msgId, string $body, int $flags) use ($sched, $echoAddr): void {
                $sched->deliver($echoAddr, $msgId, $body, $fd);
            });
            Logger::info("app ready (no gateway, actor mode)", ["host" => $host, "port" => $port]);
            $server->start();
            return;
        }

        $server->onFrame(function (int $fd, int $msgId, string $body, int $flags) use ($server, $dropOnly, $profOn): void {
            if ($profOn) {
                static $seen = 0;
                $seen++;
                if ($seen % 500000 === 0) {
                    $p = \TSF\Transport\Server::profileSnapshot();
                    if ($p["n"] > 0) {
                        fwrite(STDERR, sprintf(
                            "PROFILE n=%d decode=%.0f substr=%.0f call=%.0f feed=%.0f (ns)\n",
                            $p["n"],
                            $p["decode_ns"],
                            $p["substr_ns"],
                            $p["call_ns"],
                            $p["total_ns"]
                        ));
                    }
                }
            }
            if ($dropOnly) {
                return;
            }
            if ($msgId === Msg::ECHO_REQ) {
                $server->sendFrame($fd, Msg::ECHO_RESP, $body);
            }
        });
        Logger::info("app ready (no gateway)", ["host" => $host, "port" => $port]);
        $server->start();
        return;
    }

    // ---- 会话 / 鉴权 / 限流 / 心跳 ----
    $heartbeat = Config::getInt("server.heartbeat_sec", 30);
    $sessions = new SessionManager($heartbeat);
    $auth = new TokenAuth(
        Config::getMap("server.auth.tokens"),
        Config::getString("server.auth.secret", "")
    );
    $limiter = new RateLimiter(
        Config::getInt("server.rate_per_sec", 50),
        Config::getInt("server.rate_burst", 100)
    );

    $gw = new Gateway(
        $server,
        $sessions,
        $auth,
        $limiter,
        $heartbeat,
        1000,
        Config::getBool("server.auth.require", true),
        $payloadCodec
    );
    // 背压阈值：连续这么多次发送失败就断开该连接（慢客户端不拖累整个 worker）
    $gw->setSendFailLimit(Config::getInt("server.send_fail_limit", 50));

    // ---- 业务处理器 ----
    // 用 $gw->send 而不是 $server->sendFrame：
    // 只有经过 Gateway 才能统计发送失败、触发背压。
    //
    // handler 的载荷参数按 payload 选具体类型（全框架不出现 mixed，见 README §5.8）：
    //   payload=raw  -> string $body
    //   其他 payload -> string|array|object $data
    if ($proto->payloadName() !== "raw") {
        $gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, string|array|object $data) use ($gw): void {
            $gw->send($fd, Msg::ECHO_RESP, $data);
        });

        $gw->on(1003, function (int $fd, int $uid, string|array|object $data) use ($gw): void {
            $gw->send($fd, 1004, ["uid" => $uid]);
        });
    } else {
        $gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, string $body) use ($gw): void {
            $gw->send($fd, Msg::ECHO_RESP, $body);
        });

        // whoami：验证登录后 uid 已绑定
        $gw->on(1003, function (int $fd, int $uid, string $body) use ($gw): void {
            $gw->send($fd, 1004, "uid=" . $uid);
        });
    }

    $gw->boot();

    Logger::info("app ready", [
        "host" => $host,
        "port" => $port,
        "transport" => Config::getString("server.transport", "tcp"),
        "frame" => $proto->frameName(),
        "payload" => $proto->payloadName(),
        "heartbeat_sec" => $heartbeat,
        "require_auth" => Config::getBool("server.auth.require", true),
    ]);
    $server->start();
}
