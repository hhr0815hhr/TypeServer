<?php

use Game\App\ClusterPump;
use Game\App\RaidService;
use Game\Protocol\Cmd;
use TSF\Cluster\Cluster;
use TSF\Gateway\Gateway;
use TSF\Gateway\RateLimiter;
use TSF\Gateway\SessionManager;
use TSF\Gateway\TokenAuth;
use TSF\Gm\Registry;
use TSF\Gm\Result;
use TSF\Gm\Server as GmServer;
use TSF\Kernel\AppConfig;
use TSF\Kernel\Bootstrap;
use TSF\Kernel\Logger;
use TSF\Protocol\ProtocolStack;
use TSF\Service\Scheduler;
use TSF\Service\Service;
use TSF\Transport\Server;
use TSF\Transport\ServerFactory;

/**
 * 规则热更示例：AOT 结算 + 脚本规则 + 灰度 + GM 重载。
 *
 *   main()               串联
 *   buildProtocol()      分帧 + 消息体
 *   buildServer()        TCP
 *   buildGateway()       鉴权 / 限流 / 会话
 *   buildRaid()          结算服（持有脚本 Manager 与钱包）
 *   registerHandlers()   网络 → 内部消息
 *   buildGm()            script.reload / script.gray / loot.compare
 *
 * 端口 9603（游戏）/ 9702（GM，仅 127.0.0.1）。
 */
function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $cfg = AppConfig::load();

    // 端口可被环境变量覆盖：同机跑两个节点时必须分开（Swoole 构造时 bind，
    // 来不及用 reuse_port）。生产服务不该这么做——那是网关指向一个没人监听的地址
    $envPort = getenv("TSF_PORT");
    if (is_string($envPort) && $envPort !== "" && (int) $envPort > 0) {
        $cfg->port = (int) $envPort;
    }

    $proto = buildProtocol($cfg);
    $server = buildServer($cfg, $proto);

    $sessions = new SessionManager($cfg->heartbeatSec);
    $gw = buildGateway($cfg, $server, $proto, $sessions);

    $raidAddr = buildRaid($gw, $base . "/scripts");
    registerHandlers($gw, $raidAddr);
    $gw->boot();

    // 集群可选：不设 RULE_HOT_NODE 就是单机，行为与之前一字不变
    $pumpAddr = buildCluster($raidAddr);

    $gmAddr = buildGm($raidAddr, $pumpAddr);

    logReady($cfg, $proto, $raidAddr, $gmAddr, $pumpAddr);
    $server->start();
}

function buildProtocol(AppConfig $cfg): ProtocolStack
{
    return ProtocolStack::fromConfig($cfg->frameName, $cfg->payloadName);
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

function buildGateway(AppConfig $cfg, Server $server, ProtocolStack $proto, SessionManager $sessions): Gateway
{
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
 * 结算服。
 *
 * 脚本目录作为构造参数传进去，而不是在 Service 里用 `__DIR__`：
 * AOT 产物运行时的 `__DIR__` 是 main.php 所在目录，但部署时脚本可能放在
 * 别的位置（独立挂载、版本目录）。路径从装配层注入，Service 不猜。
 */
function buildRaid(Gateway $gw, string $scriptDir): int
{
    return Scheduler::instance()->spawnOf(function () use ($gw, $scriptDir): Service {
        return new RaidService($gw, $scriptDir);
    });
}

function registerHandlers(Gateway $gw, int $raidAddr): void
{
    $gw->on(Cmd::RAID_REQ, function (int $fd, int $uid, string $body) use ($raidAddr): void {
        if ($uid <= 0) {
            return;
        }
        $kills = 1;
        if ($body !== "") {
            $kills = (int) $body;
        }
        if ($kills <= 0) {
            $kills = 1;
        }
        if ($kills > 100) {
            $kills = 100;
        }
        Scheduler::instance()->deliver($raidAddr, Cmd::SVC_RAID, $fd . "|" . $uid . "|" . $kills);
    });
}

/**
 * 集群装配（可选）。
 *
 * 环境变量：
 *   RULE_HOT_NODE          节点号 1..255；不设 = 单机
 *   RULE_HOT_CLUSTER_ADDR  本节点监听，如 tcp://127.0.0.1:9613
 *   RULE_HOT_PEER_NODE     对端节点号
 *   RULE_HOT_PEER_ADDR     对端地址，如 tcp://127.0.0.1:9614
 *
 * 用 `watchPeer` 而不是 `connect`：connect 是同步阻塞的，对端还没起来时会挡住
 * 整个启动流程 2 秒；watchPeer 挂上去由 poll 推进，断了还会自动重连。
 *
 * @return int pump 的服务地址；0 = 没启用
 */
function buildCluster(int $raidAddr): int
{
    $node = (int) (getenv("RULE_HOT_NODE") ?: 0);
    if ($node <= 0) {
        return 0;
    }
    $addr = getenv("RULE_HOT_CLUSTER_ADDR");
    if ($addr === false || $addr === "") {
        Logger::warn("RULE_HOT_NODE 已设置但没有监听地址，集群未启用", []);
        return 0;
    }

    $cluster = new Cluster($node, $addr);
    if (!$cluster->listen()) {
        Logger::error("集群监听失败", ["node" => $node, "addr" => $addr]);
        return 0;
    }

    $peerNode = (int) (getenv("RULE_HOT_PEER_NODE") ?: 0);
    $peerAddr = getenv("RULE_HOT_PEER_ADDR");
    if ($peerNode > 0 && $peerAddr !== false && $peerAddr !== "") {
        if (!$cluster->watchPeer($peerNode, $peerAddr)) {
            Logger::warn("watchPeer 失败", ["node" => $peerNode, "addr" => $peerAddr]);
        }
    }

    // 把广播发送端注入规则宿主。必须在 RaidService 的 onStart 之后：
    // spawnOf 是同步执行的，返回时 onStart 已经跑完，所以这里安全
    $svc = Scheduler::instance()->serviceOf($raidAddr);
    if ($svc instanceof RaidService) {
        $svc->enableCluster($cluster);
    }

    return Scheduler::instance()->spawnOf(function () use ($cluster, $raidAddr): Service {
        return new ClusterPump($cluster, $raidAddr);
    });
}

/**
 * GM 通道：独立端口，只绑 127.0.0.1。
 *
 * token 取 `TSF_GM_TOKEN`，没有就用本机默认值并告警。
 * 不做"空 token 也放行"——那种模式一定会被带到线上。
 */
function buildGm(int $raidAddr, int $pumpAddr): int
{
    $token = getenv("TSF_GM_TOKEN");
    if ($token === false || $token === "") {
        $token = "local-dev-only";
        Logger::warn("gm token 未设置，使用本机默认值（仅调试用）", []);
    }

    // GM 端口允许覆盖：同机跑两个节点时必须分开，否则第二个起不来
    $gmPort = (int) (getenv("RULE_HOT_GM_PORT") ?: 9702);

    $addr = Scheduler::instance()->spawnOf(function () use ($token, $gmPort): Service {
        return new GmServer("127.0.0.1", $gmPort, $token);
    });

    Registry::on("script.reload", function (array $args) use ($raidAddr): Result {
        $svc = Scheduler::instance()->serviceOf($raidAddr);
        if (!$svc instanceof RaidService) {
            return Result::fail("raid 服务未就绪");
        }
        $name = isset($args["name"]) ? (string) $args["name"] : "";
        $r = $svc->gmReload($name);
        if (!isset($r["ok"]) || $r["ok"] !== true) {
            return Result::fail(isset($r["msg"]) ? (string) $r["msg"] : "重载失败");
        }
        $names = isset($r["names"]) && is_array($r["names"]) ? $r["names"] : [];
        return Result::ok(implode(", ", $names) === "" ? "（没有注册脚本）" : implode(", ", $names));
    }, "重载脚本：args {name}；name 为空 = 全部");

    Registry::on("script.gray", function (array $args) use ($raidAddr): Result {
        $svc = Scheduler::instance()->serviceOf($raidAddr);
        if (!$svc instanceof RaidService) {
            return Result::fail("raid 服务未就绪");
        }
        $name = isset($args["name"]) ? (string) $args["name"] : "";
        $pct = isset($args["pct"]) ? (int) $args["pct"] : 0;
        if ($name === "") {
            return Result::fail("name 必填");
        }
        $r = $svc->gmGray($name, $pct);
        if (!isset($r["ok"]) || $r["ok"] !== true) {
            return Result::fail(isset($r["msg"]) ? (string) $r["msg"] : "设置失败");
        }
        return Result::ok("gray " . $name . " = " . (string) $pct . "%");
    }, "灰度：args {name, pct}；pct 0–100，默认 0（不启用新版本）");

    Registry::on("script.status", function (array $args) use ($raidAddr): Result {
        $svc = Scheduler::instance()->serviceOf($raidAddr);
        if (!$svc instanceof RaidService) {
            return Result::fail("raid 服务未就绪");
        }
        return Result::ok($svc->gmStatus());
    }, "查看脚本加载、灰度、累计掉落与拒绝次数");

    Registry::on("loot.compare", function (array $args) use ($raidAddr): Result {
        $svc = Scheduler::instance()->serviceOf($raidAddr);
        if (!$svc instanceof RaidService) {
            return Result::fail("raid 服务未就绪");
        }
        $uid = isset($args["uid"]) ? (int) $args["uid"] : 1001;
        $kills = isset($args["kills"]) ? (int) $args["kills"] : 10;
        if ($uid <= 0 || $kills <= 0) {
            return Result::fail("uid / kills 必须为正");
        }
        return Result::ok($svc->gmCompare($uid, $kills));
    }, "对拍：同 uid/kills 下 AOT 规则与脚本规则是否一致（args {uid,kills}）");

    Registry::on("script.reload-cluster", function (array $args) use ($raidAddr): Result {
        $svc = Scheduler::instance()->serviceOf($raidAddr);
        if (!$svc instanceof RaidService) {
            return Result::fail("raid 服务未就绪");
        }
        $name = isset($args["name"]) ? (string) $args["name"] : "";
        if ($name === "") {
            return Result::fail("name 必填（跨节点重载要明确脚本，避免误广播）");
        }
        $r = $svc->gmReloadCluster($name);
        if (!isset($r["ok"]) || $r["ok"] !== true) {
            return Result::fail(isset($r["msg"]) ? (string) $r["msg"] : "广播失败");
        }
        $reqId = isset($r["reqId"]) ? (string) $r["reqId"] : "";
        if ($reqId === "") {
            return Result::ok("本机已重载；没有已连上的节点（cluster.status 看 peer 数）");
        }
        return Result::ok("reqId=" . $reqId);
    }, "跨节点重载：args {name}；本机先重载，再广播给已连节点");

    Registry::on("script.cluster-status", function (array $args) use ($raidAddr): Result {
        $svc = Scheduler::instance()->serviceOf($raidAddr);
        if (!$svc instanceof RaidService) {
            return Result::fail("raid 服务未就绪");
        }
        $reqId = isset($args["req"]) ? (string) $args["req"] : "";
        return Result::ok($svc->gmClusterStatus($reqId));
    }, "查广播结果：args {req}；done=1 才是全部节点都回了");

    Registry::on("cluster.status", function (array $args) use ($pumpAddr): Result {
        $p = Scheduler::instance()->serviceOf($pumpAddr);
        if (!$p instanceof ClusterPump) {
            return Result::fail("集群未启用");
        }
        return Result::ok($p->stats());
    }, "集群连接与收包统计（peers/handled/dropped）");

    Registry::on("loot.bench", function (array $args) use ($raidAddr): Result {
        $svc = Scheduler::instance()->serviceOf($raidAddr);
        if (!$svc instanceof RaidService) {
            return Result::fail("raid 服务未就绪");
        }
        $n = isset($args["n"]) ? (int) $args["n"] : 20000;
        $kills = isset($args["kills"]) ? (int) $args["kills"] : 10;
        return Result::ok($svc->gmBench($n, $kills));
    }, "进程内对照：AOT 规则 vs 脚本规则，args {n, kills}");

    return $addr;
}

function logReady(AppConfig $cfg, ProtocolStack $proto, int $raidAddr, int $gmAddr, int $pumpAddr): void
{
    Logger::info("rule-hot ready", [
        "host" => $cfg->host,
        "port" => $cfg->port,
        "raid" => $raidAddr,
        "gm" => $gmAddr,
        "cluster_pump" => $pumpAddr,
        "frame" => $proto->frameName(),
        "payload" => $proto->payloadName(),
    ]);
}
