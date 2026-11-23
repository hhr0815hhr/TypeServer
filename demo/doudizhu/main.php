<?php

// 斗地主：WebSocket 服务端。
//
// 装配顺序：协议栈 → 传输 → Gateway（推送要用它）→ 牌桌 Service → 消息转发 → 断线清理。

use Ddz\DdzService;
use Ddz\Msg as DdzMsg;
use Ddz\PlayerRow;
use TSF\Db\Db;
use TSF\Db\Migrator;
use TSF\Db\Repo;
use TSF\EventLog\EventLog;
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

    // ---- 部署子命令 ----
    // `tsf migrate .` 的做法是编译后运行 `./doudizhu migrate`，所以产物**必须**
    // 自己处理这个参数；否则它会被当成"没带参数"直接起服务，部署脚本就挂在那里
    // 等一个永远不返回的迁移。duel 的 main.php 里也是同样的分支。
    $cli = isset($_SERVER["argv"][1]) ? (string) $_SERVER["argv"][1] : "";
    if ($cli === "migrate" || $cli === "migrate-status") {
        $migDb = new Db(Config::getString("db.dsn", "sqlite:" . $base . "/var/ddz.db"));
        $migrator = new Migrator($migDb, $base . "/migrations");
        if ($cli === "migrate") {
            // up() 的返回值不是"已执行的迁移列表"（count 出来是 4，而实际只有 1 个 .sql），
            // 拿它报数会给出错误信息。部署脚本只关心成败，所以不报条数。
            $migrator->up();
            echo "  done" . PHP_EOL;
            echo "migrate ok" . PHP_EOL;
            Bootstrap::exitWith(0);
        }
        $pending = $migrator->status();
        foreach ($pending as $name) {
            echo "  pending " . (string) $name . PHP_EOL;
        }
        // 有 pending 时要让脚本看出来；exit(1) 在 AOT 下会被吞成 0
        Bootstrap::exitWith($pending === [] ? 0 : 1);
    }

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
    // 落库：余额表 + 对局日志。dsn 里 ${base} 由 Bootstrap 展开。
    $db = new Db(Config::getString("db.dsn", "sqlite:" . $base . "/var/ddz.db"));
    $players = new Repo(PlayerRow::class, $db);
    $settleLog = new EventLog($base . "/var/settle.log");

    $turnSec = Config::getInt("game.turn_timeout_sec", 30);
    $ddzAddr = Scheduler::instance()->spawnOf(function () use ($gw, $turnSec, $players, $settleLog): DdzService {
        return new DdzService(function (int $fd, int $msgId, string|array|object $data) use ($gw): void {
            $gw->send($fd, $msgId, $data);
        }, $turnSec, $players, $settleLog);
    });

    // ---- 客户端消息 → 牌桌 ----
    // 统一带 uid 和 fd：Service 需要知道"谁发的"和"回哪去"。
    // 载荷用 JSON 字符串而不是数组：Message::data 的类型是 string|array|object，
    // 数组里再嵌联合类型会踩全框架「不出现 mixed」的红线。
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

    $gw->on(DdzMsg::ADD_BOT, function (int $fd, int $uid, string|array|object $data) use ($ddzAddr): void {
        Scheduler::instance()->deliver($ddzAddr, DdzMsg::ADD_BOT, json_encode([
            "uid" => $uid, "fd" => $fd, "p" => $data,
        ]));
    });

    // 断线清理：不处理的话，关掉标签页座位会一直被占，再开就是「桌子满了」。
    // 回调是 **(fd, uid) 两个参数**——Gateway 里是 $cb($fd, $uid)，
    // 只声明一个参数在 AOT 下会崩（报 stdClass->{closure}()），编译期不报。
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
        "turn_timeout_sec" => $turnSec,
    ]);

    $server->start();
}
