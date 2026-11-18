<?php

// sharded-world：同一个可执行文件跑两种角色
//
//   ./sharded_world router 9500     入口：按 uid 哈希告诉客户端去哪个节点
//   ./sharded_world node 1 9501     世界节点 1（启动时注册到注册表）
//   ./sharded_world node 2 9502     世界节点 2
//
// 为什么用「一份代码 + 命令行参数」而不是三个工程：三个工程要编译三遍，
// 而且节点和路由的公共部分（Gateway 装配）会重复。生产上一般是一个二进制
// 加配置文件，这里用参数更直观。
//
// 注册表用 FileRegistry（同机共享一个目录）。它自带 TTL：节点 kill -9 之后
// 心跳停了，5 秒后自动从表里消失 —— 不需要显式注销，这是它比"自己维护登记表"强的地方。

use Shard\ShardMsg as ShardMsg;
use TSF\Discovery\FileRegistry;
use TSF\Discovery\LocalNode;
use TSF\Discovery\NodeInfo;
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
    Bootstrap::boot($base, "sharded-world");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $argv = $_SERVER["argv"] ?? [];
    $role = isset($argv[1]) ? (string) $argv[1] : "node";

    // tsf migrate . 会运行 `./binary migrate`，产物必须自己处理（见 04 篇）
    if ($role === "migrate") {
        echo "  nothing to migrate" . PHP_EOL;
        exit(0);
    }

    if ($role === "router") {
        $port = isset($argv[2]) ? (int) $argv[2] : 9500;
        runRouter($base, $port);
        return;
    }

    $nodeId = isset($argv[2]) ? (int) $argv[2] : 1;
    $port = isset($argv[3]) ? (int) $argv[3] : (9500 + $nodeId);
    runNode($base, $nodeId, $port);
}

/** 统一的 Gateway 装配（router 和 node 都要） */
function makeGateway(int $port): array
{
    $proto = ProtocolStack::fromConfig(
        Config::getString("protocol.frame", "native"),
        Config::getString("protocol.payload", "json")
    );

    $server = ServerFactory::make(
        Config::getString("server.transport", "ws"),
        Config::getString("server.host", "0.0.0.0"),
        $port,
        $proto->frame(),
        [
            "worker_num" => 1,
            "max_conn" => Config::getInt("server.max_conn", 200),
            "nodelay" => Config::getBool("server.nodelay", true),
        ]
    );

    $heartbeat = Config::getInt("server.heartbeat_sec", 60);
    $sessions = new SessionManager($heartbeat);
    $auth = new TokenAuth(
        Config::getMap("server.auth.tokens"),
        Config::getString("server.auth.secret", "")
    );
    $limiter = new RateLimiter(100, 200);

    $gw = new Gateway(
        $server, $sessions, $auth, $limiter, $heartbeat, 1000,
        Config::getBool("server.auth.require", true), $proto->payload()
    );
    $gw->setSendFailLimit(50);

    return [$gw, $server];
}

/**
 * 入口服务：不承载世界，只回答「这个 uid 该去哪个节点」。
 *
 * 分片规则是 uid % 节点数。真实项目会换成一致性哈希（增删节点时迁移量小），
 * 但 demo 里取模足够说明问题：**同一个 uid 每次都会落到同一个节点**。
 */
function runRouter(string $base, int $port): void
{
    [$gw, $server] = makeGateway($port);

    $gw->on(ShardMsg::WHERE, function (int $fd, int $uid, string|array|object $data) use ($gw, $base): void {
        // 这里**不用 Registry::alive()**，而是直接读注册目录里的 JSON。
        //
        // 原因是 AOT 的硬限制：`alive()` 返回的是裸 `array`，元素类型丢成 php::Var，
        // 在元素上调用 `->nodeId()` 会被当成 "callback 字符串"解析，运行时报
        //   Invalid callback TSF\Discovery\NodeInfo::nodeId, class ... does not have a method "nodeId"
        // —— 报错信息完全指不到真实原因。加 instanceof 收窄也没用。
        //
        // 所以这里换成读文件：注册表本来就是「目录下每个节点一个 JSON」，
        // 字段是 id / ep / seen / tags。全是数组操作，不碰对象方法。
        // 代价是自己实现一遍 TTL 判断（原来的 alive() 内部也是这么干的）。
        $dir = Config::getString("cluster.registry_dir", $base . "/var/registry");
        $ttl = Config::getInt("cluster.ttl_ms", 5000);
        $now = (int) (microtime(true) * 1000);

        $nodes = [];
        $files = glob($dir . "/*.json");
        if (is_array($files)) {
            foreach ($files as $file) {
                $raw = file_get_contents($file);
                if (!is_string($raw) || $raw === "") {
                    continue;
                }
                $row = json_decode($raw, true);
                if (!is_array($row)) {
                    continue;
                }
                // 注意：**心跳不写在 .json 里**。
                // FileRegistry::register() 写 `<id>.json`（seen = 注册时刻），
                // 而 heartbeat() 只 touch 一个 `<id>.beat` 文件 —— 所以只读 json
                // 的 seen 会以为节点"注册后就没再动过"，5 秒后集体判死。
                // 正确做法是拿 .beat 的 mtime 当最后心跳（它每次 beat 都会被重写）。
                $seen = (int) ($row["seen"] ?? 0);
                $id = (int) ($row["id"] ?? 0);
                $beatFile = $dir . "/" . $id . ".beat";
                if (is_file($beatFile)) {
                    $mt = filemtime($beatFile);
                    if (is_int($mt) && $mt * 1000 > $seen) {
                        $seen = $mt * 1000;
                    }
                }
                if ($now - $seen > $ttl) {
                    continue;   // 心跳停了：这个节点已经死了
                }
                $nodes[] = [
                    "id" => (int) ($row["id"] ?? 0),
                    "ep" => (string) ($row["ep"] ?? ""),
                ];
            }
        }

        $n = count($nodes);
        if ($n === 0) {
            $gw->send($fd, ShardMsg::NO_NODE, ["why" => "当前没有可用节点"]);
            return;
        }

        // 分片：uid 取模。真实项目会用一致性哈希（增删节点时迁移量小）
        $idx = $uid % $n;
        if ($idx < 0) {
            $idx = 0;
        }
        $pick = $nodes[$idx];

        Logger::info("route", ["uid" => $uid, "node" => $pick["id"], "total" => $n]);

        $gw->send($fd, ShardMsg::GO, [
            "go" => "ws://" . $pick["ep"],
            "node" => $pick["id"],
            "total" => $n,
        ]);
    });

    $gw->boot();
    Logger::info("router ready", ["port" => $port]);
    $server->start();
}

/** 一个世界节点：mmo 逻辑 + 把自己注册到注册表 */
function runNode(string $base, int $nodeId, int $port): void
{
    $registry = new FileRegistry(
        Config::getString("cluster.registry_dir", $base . "/var/registry"),
        Config::getInt("cluster.ttl_ms", 5000)
    );

    // LocalNode 是 Service：spawn 后 onStart 会注册自己，并按 beatMs 续心跳。
    // 不需要手动 unregister —— kill -9 之后心跳停了，TTL 到期自动消失。
    $info = new NodeInfo($nodeId, "127.0.0.1:" . $port, "json", ["role" => "world"]);
    Scheduler::instance()->spawnOf(function () use ($registry, $info): LocalNode {
        return new LocalNode($registry, $info, Config::getInt("cluster.beat_ms", 1000));
    });

    [$gw, $server] = makeGateway($port);

    // 世界逻辑用复用的 MmoService（和 mmo-mini 同一份代码）
    $turnPublish = function (int $fd, int $msgId, string|array|object $data) use ($gw): void {
        $gw->send($fd, $msgId, $data);
    };
    $worldAddr = Scheduler::instance()->spawnOf(function () use ($turnPublish): \Mmo\MmoService {
        return new \Mmo\MmoService(
            $turnPublish,
            Config::getInt("game.map_w", 2000),
            Config::getInt("game.map_h", 2000),
            Config::getInt("game.cell", 200),
            Config::getInt("game.speed", 6),
            Config::getInt("game.frame_ms", 50),
            Config::getInt("game.max_players", 8)
        );
    });

    $gw->on(\Mmo\Msg::JOIN, function (int $fd, int $uid, string|array|object $data) use ($worldAddr): void {
        Scheduler::instance()->deliver($worldAddr, \Mmo\Msg::JOIN, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->on(\Mmo\Msg::MOVE, function (int $fd, int $uid, string|array|object $data) use ($worldAddr): void {
        Scheduler::instance()->deliver($worldAddr, \Mmo\Msg::MOVE, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->on(\Mmo\Msg::LEAVE, function (int $fd, int $uid, string|array|object $data) use ($worldAddr): void {
        Scheduler::instance()->deliver($worldAddr, \Mmo\Msg::LEAVE, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });
    $gw->onDisconnect(function (int $fd, int $uid) use ($worldAddr): void {
        Scheduler::instance()->deliver($worldAddr, \Mmo\Msg::LEAVE, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => ["disc" => 1],
        ]));
    });

    $gw->boot();
    Logger::info("world node ready", ["node" => $nodeId, "port" => $port, "endpoint" => "127.0.0.1:" . $port]);
    $server->start();
}
