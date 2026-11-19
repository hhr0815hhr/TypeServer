<?php

use Game\Battle\BattleRoom;
use Game\Battle\BattleService;
use Game\Battle\Output;
use Game\Battle\SkillTable;
use Game\Msg;
use TSF\Gateway\Gateway;
use TSF\Gateway\RateLimiter;
use TSF\Gateway\SessionManager;
use TSF\Gateway\TokenAuth;
use TSF\Gm\Registry;
use TSF\Gm\Result;
use TSF\Gm\Server as GmServer;
use TSF\Gm\Task;
use TSF\Kernel\AppConfig;
use TSF\Kernel\Bootstrap;
use TSF\Kernel\Logger;
use TSF\Protocol\Msg as SysMsg;
use TSF\Protocol\ProtocolStack;
use TSF\Service\Scheduler;
use TSF\Service\Service;
use TSF\Transport\Server;
use TSF\Transport\ServerFactory;

/**
 * 启动流程仍然是六步，只是多了"装配战斗层"这一环：
 *
 *   main()              串联
 *   AppConfig::load()   读配置
 *   buildProtocol()     协议栈
 *   buildServer()       传输层
 *   buildGateway()      会话 / 鉴权 / 限流
 *   buildBattle()       战斗房间 + 推送出口（两个 Service）
 *   registerHandlers()  业务：网络输入 → 房间消息
 */
function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $cfg = AppConfig::load();
    $proto = buildProtocol($cfg);
    $server = buildServer($cfg, $proto);

    // 会话管理器在 main 里建，是因为它有两个使用者：
    // Gateway（鉴权/心跳）和 Output（uid → fd 换算）。
    // 让 Gateway 内部自己 new 一个，Output 就拿不到同一个实例了。
    $sessions = new SessionManager($cfg->heartbeatSec);
    $gw = buildGateway($cfg, $server, $proto, $sessions);

    $addrs = buildBattle($base, $gw, $sessions, $server);
    $battleAddr = $addrs[0];

    registerHandlers($gw, $proto, $battleAddr, $server);
    registerPipe($server, $gw, $sessions, $battleAddr);
    $gw->boot();

    // GM 通道：独立端口，只给"手动重载配置"这类确定性操作。
    // 自动轮询（SkillTable::reloadIfChanged）仍然在跑，两者不冲突——
    // 轮询负责"改了自然生效"，GM 负责"现在、立刻、并且我要看到结果"。
    $gmAddr = buildGm($base, $battleAddr);

    logReady($cfg, $proto, $battleAddr, $gmAddr);
    $server->start();
}

/**
 * 起 GM 服务并注册命令，返回 GM 服务地址（0 表示没起）。
 *
 * token 取 `TSF_GM_TOKEN`，没有就用一个本机默认值并告警。
 * 这里**不做"空 token 也放行"**——那种模式一定会被带到线上（README 23.2 第一条）。
 */
function buildGm(string $base, int $battleAddr): int
{
    $token = getenv("TSF_GM_TOKEN");
    if ($token === false || $token === "") {
        $token = "local-dev-only";
        Logger::warn("gm token 未设置，使用本机默认值（仅限调试，不要对公网开放）", []);
    }

    $addr = Scheduler::instance()->spawnOf(function () use ($token): Service {
        return new GmServer("127.0.0.1", 9701, $token);
    });

    Registry::on("config.reload", function (array $args) use ($base, $battleAddr): Result {
        $svc = Scheduler::instance()->serviceOf($battleAddr);
        if (!$svc instanceof BattleService) {
            return Result::fail("battle 服务未就绪");
        }
        $r = $svc->gmReloadConfig($base);
        if (!isset($r["ok"]) || $r["ok"] !== true) {
            return Result::fail(isset($r["msg"]) ? (string) $r["msg"] : "重载失败");
        }
        return Result::ok(json_encode([
            "keys" => $r["keys"] ?? 0,
            "hash" => $r["hash"] ?? "",
        ]));
    }, "重载 config/skill.php，同步返回结果");

    Registry::on("player.setHp", function (array $args) use ($battleAddr): Result {
        $uid = (int) ($args["uid"] ?? 0);
        $hp = (int) ($args["hp"] ?? -1);
        if ($uid <= 0) {
            return Result::fail("uid 必填");
        }
        if ($hp < 0) {
            return Result::fail("hp 不能为负");
        }
        $svc = Scheduler::instance()->serviceOf($battleAddr);
        if (!$svc instanceof BattleService) {
            return Result::fail("battle 服务未就绪");
        }
        $r = $svc->gmSetHp($uid, $hp);
        if (!isset($r["ok"]) || $r["ok"] !== true) {
            return Result::fail(isset($r["msg"]) ? (string) $r["msg"] : "设置失败");
        }
        return Result::ok(json_encode([
            "uid" => $r["uid"] ?? 0,
            "before" => $r["before"] ?? 0,
            "hp" => $r["hp"] ?? 0,
        ]));
    }, "改玩家血量，同步返回改前值");

    Registry::on("player.batchSetHp", function (array $args) use ($battleAddr): Result {
        $hp = (int) ($args["hp"] ?? -1);
        if ($hp < 0) {
            return Result::fail("hp 不能为负");
        }
        $svc = Scheduler::instance()->serviceOf($battleAddr);
        if (!$svc instanceof BattleService) {
            return Result::fail("battle 服务未就绪");
        }
        // 同步还是异步，由命令自己判断快慢，不由框架猜
        $async = isset($args["async"]) && $args["async"] === true;
        if (!$async) {
            $body = json_encode($svc->gmBatchSetHp($hp));
            return Result::ok(is_string($body) ? $body : "{}");
        }
        $total = $svc->online();
        if ($total <= 0) {
            return Result::fail("当前没有在线玩家");
        }
        $id = Task::create("player.batchSetHp", $total);
        $svc->gmStartBatchHp($id, $hp);
        $body = json_encode(["task" => $id, "status" => "running", "total" => $total]);
        return Result::ok(is_string($body) ? $body : "{}");
    }, "批量设血量（args.async=true 走异步，返回 task id）");

    // 没这条的话，运维只能靠翻代码或问人知道有哪些命令——
    // 而 GM 通道恰恰是"出事时才想起来"的地方，文档必须自己能报出来
    Registry::on("help", function (array $args): Result {
        // 中文别转成 \uXXXX：这条命令是给人看的，可读性优先于字节数
        $body = json_encode(["commands" => Registry::docs()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return Result::ok(is_string($body) ? $body : "{}");
    }, "列出所有 GM 命令");

    Registry::on("task.status", function (array $args): Result {
        $id = isset($args["id"]) ? (string) $args["id"] : "";
        if ($id === "") {
            return Result::fail("id 必填");
        }
        $t = Task::status($id);
        if ($t === null) {
            return Result::fail("任务不存在或已过期: " . $id);
        }
        $body = json_encode($t);
        return Result::ok(is_string($body) ? $body : "{}");
    }, "查异步任务的进度与最终结果");

    return $addr;
}

/** 分帧 + 消息体，两层都可换（配置即可，不改代码） */
function buildProtocol(AppConfig $cfg): ProtocolStack
{
    return ProtocolStack::fromConfig($cfg->frameName, $cfg->payloadName);
}

/**
 * 传输层：tcp 或 ws。
 *
 * 走工厂而不是在 if/else 里分别 new：AOT 下局部变量的类型由首次赋值推断，
 * 分支里分别赋 TcpServer / WsServer 会报 "Cannot re-assign typed object"。
 * 工厂返回类型统一是 Server，所以这里能声明返回类型。
 */
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

function buildGateway(AppConfig $cfg, Server $server, ProtocolStack $proto, SessionManager $sessions): Gateway
{
    $auth = new TokenAuth($cfg->authTokens, $cfg->authSecret);
    $limiter = new RateLimiter($cfg->ratePerSec, $cfg->rateBurst);

    // payload 为 raw 时不往 Gateway 传编解码器 —— 省掉每帧一次空转
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
 * 战斗层：两个 Service，一个算、一个发。
 *
 * 为什么必须用 `spawnOf` 而不是 `new`：
 *   Service 只有在 Scheduler 里注册并绑定地址后，才能收消息、才能用定时器。
 *   `new` 出来的对象只是一个普通对象，它的 onMessage 永远不会被调用。
 *
 * 为什么工厂闭包要捕获参数：
 *   AOT 不支持"按名字 new 带参数"的注册表工厂，所以配置只能靠捕获。
 *   BattleRoom 的配置（地图尺寸、技能表路径）在装配期就固定了。
 *
 * 注意 worker 数：Service 存在进程内存里，`worker_num > 1` 时每个 worker
 * 各有一张地图、各推各的——同一个玩家在 A worker，另一个在 B worker，
 * 他们在游戏里永远看不到对方。所以本工程必须 `worker_num = 1`；
 * 要扩到多 worker，得先把"玩家 → worker"的归属固定下来（一致性哈希），
 * 这不是改配置能解决的。
 *
 * @return array<int, int> [battleAddr, outputAddr]
 */
function buildBattle(string $base, Gateway $gw, SessionManager $sessions, Server $server): array
{
    $outputAddr = Scheduler::instance()->spawnOf(function () use ($gw, $sessions, $server): Service {
        // 推送出口带上"跨 worker 广播"能力：发不出去的 uid 交给其他 worker 试
        return new Output($gw, $sessions, function (string $data) use ($server): void {
            bridgeToOtherWorkers($server, $data);
        });
    });

    $battleAddr = Scheduler::instance()->spawnOf(function () use ($base, $outputAddr): Service {
        $skills = new SkillTable($base . "/config/skill.php");
        $svc = new BattleService(new BattleRoom($skills));
        $svc->setOutput($outputAddr);
        return $svc;
    });

    return [$battleAddr, $outputAddr];
}

/**
 * 业务处理器。
 *
 * 这里只做一件事：把网络输入翻译成房间消息。**不放任何玩法判断**。
 * 距离、冷却、命中全部在 BattleRoom 里算——如果这里写 `if ($dmg > ...)`，
 * 就等于把服务端权威拆到了两个地方，改动时必然漏改一处。
 *
 * 路由用 uid 不用房间号：本工程只有一张常驻地图，uid 就够定位。
 * 多地图时这里要查一次 uid → 地图地址的转发表，接口不变。
 */
function registerHandlers(Gateway $gw, ProtocolStack $proto, int $battleAddr, Server $server): void
{
    // 冒烟用：回显，确认链路通
    $gw->on(SysMsg::ECHO_REQ, function (int $fd, int $uid, string $body) use ($gw): void {
        $gw->send($fd, SysMsg::ECHO_RESP, $body);
    });
    $gw->on(1003, function (int $fd, int $uid, string $body) use ($gw): void {
        $gw->send($fd, 1004, "uid=" . $uid);
    });

    // 进图
    $gw->on(Msg::ENTER, function (int $fd, int $uid, string $body) use ($battleAddr, $server): void {
        if ($uid <= 0) {
            return;
        }
        dispatchToBattle($server, $battleAddr, BattleService::MSG_ENTER, (string) $uid, $uid);
    });

    // 离图
    $gw->on(Msg::LEAVE, function (int $fd, int $uid, string $body) use ($battleAddr, $server): void {
        if ($uid <= 0) {
            return;
        }
        dispatchToBattle($server, $battleAddr, BattleService::MSG_LEAVE, (string) $uid, $uid);
    });

    // 移动意图：dx/dy ∈ {-1,0,1}
    $gw->on(Msg::MOVE, function (int $fd, int $uid, string $body) use ($battleAddr, $server): void {
        if ($uid <= 0) {
            return;
        }
        $p = Msg::params($body);
        $dx = isset($p["dx"]) ? (int) $p["dx"] : 0;
        $dy = isset($p["dy"]) ? (int) $p["dy"] : 0;
        dispatchToBattle($server, $battleAddr, BattleService::MSG_MOVE, $uid . "," . $dx . "," . $dy, $uid);
    });

    // 施法：只发技能号，目标由服务端按半径判定
    $gw->on(Msg::CAST, function (int $fd, int $uid, string $body) use ($battleAddr, $server): void {
        if ($uid <= 0) {
            return;
        }
        $p = Msg::params($body);
        $skill = isset($p["skill"]) ? (int) $p["skill"] : 1;
        dispatchToBattle($server, $battleAddr, BattleService::MSG_CAST, $uid . "," . $skill, $uid);
    });

    // 掉线：必须退出地图，否则会留下一个永远不动的实体 ——
    // 继续占 AOI 格子、继续被人打、继续吃推送额度
    $gw->onDisconnect(function (int $fd, int $uid) use ($battleAddr, $server): void {
        if ($uid <= 0) {
            return;
        }
        dispatchToBattle($server, $battleAddr, BattleService::MSG_LEAVE, (string) $uid, $uid);
        Logger::info("battle leave on disconnect", ["uid" => $uid]);
    });
}

/**
 * 把一条输入送到"该处理它的 worker"。
 *
 * 归属规则是 `uid % workerNum`。为什么按 uid 而不是按 fd：
 * fd 是连接在某个 worker 上的编号，断线重连就会变；按 uid 才能保证
 * "同一个玩家永远由同一个进程持有他的战斗状态"。
 *
 * 单 worker 时这一步是空操作（直接 deliver），不引入任何额外开销。
 */
function dispatchToBattle(Server $server, int $battleAddr, int $msgId, string $payload, int $uid): void
{
    $n = $server->workerNum();
    if ($n > 1 && $server->pipeSupported()) {
        $owner = $uid % $n;
        if ($owner !== $server->workerId()) {
            $server->pipeSend($owner, "in|" . $msgId . "|" . $payload);
            return;
        }
    }
    Scheduler::instance()->deliver($battleAddr, $msgId, $payload);
}

/** 把一段数据发给其他所有 worker（不含自己） */
function bridgeToOtherWorkers(Server $server, string $data): void
{
    $n = $server->workerNum();
    if ($n <= 1 || !$server->pipeSupported()) {
        return;
    }
    $me = $server->workerId();
    for ($i = 0; $i < $n; $i++) {
        if ($i !== $me) {
            $server->pipeSend($i, $data);
        }
    }
}

/**
 * worker 间通道：接两种消息。
 *
 *   in|msgId|payload        别的 worker 把"该我处理"的输入转过来
 *   out|msgId|uid1,uid2|body 别的 worker 算出推送，但玩家连在我这里
 *
 * 注意 `in` 是 3 段、`out` 是 4 段，所以用 explode 的 limit 分开处理：
 * `out` 的 body 是 JSON，里面可能含 `|`，limit=4 能保证它整段不被切开。
 */
function registerPipe(Server $server, Gateway $gw, SessionManager $sessions, int $battleAddr): void
{
    $server->onPipeMessage(function (string $data, int $from) use ($gw, $sessions, $battleAddr): void {
        if ($data === "") {
            return;
        }
        // 一次拆 4 段，按首段判断类型：
        //   in|msgId|payload        → 3 段
        //   out|msgId|uids|body     → 4 段（body 是 JSON，limit=4 保证它不被切开）
        $parts = explode("|", $data, 4);
        $kind = $parts[0];
        if ($kind === "in") {
            if (count($parts) !== 3) {
                return;
            }
            Scheduler::instance()->deliver($battleAddr, (int) $parts[1], $parts[2]);
            return;
        }
        if ($kind === "out") {
            if (count($parts) !== 4) {
                return;
            }
            $proto = (int) $parts[1];
            $body = $parts[3];
            if ($body === "") {
                return;
            }
            $uids = explode(",", $parts[2]);
            foreach ($uids as $u) {
                $fd = $sessions->fdOf((int) $u);
                if ($fd > 0) {
                    $gw->send($fd, $proto, $body);
                }
            }
        }
    });
}

function logReady(AppConfig $cfg, ProtocolStack $proto, int $battleAddr, int $gmAddr): void
{
    Logger::info("app ready", [
        "host" => $cfg->host,
        "port" => $cfg->port,
        "transport" => $cfg->transport,
        "frame" => $proto->frameName(),
        "payload" => $proto->payloadName(),
        "heartbeat_sec" => $cfg->heartbeatSec,
        "require_auth" => $cfg->requireAuth,
        "battle_addr" => $battleAddr,
        "gm_addr" => $gmAddr,
        "gm_port" => $gmAddr > 0 ? 9701 : 0,
    ]);
}
