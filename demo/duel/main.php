<?php

use Duel\DuelManager;
use Duel\DuelRecord;
use Duel\DuelService;
use Duel\Msg;
use Duel\Settle;
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
use TSF\Match\MatchService;
use TSF\Protocol\ProtocolStack;
use TSF\Service\Scheduler;
use TSF\Snapshot\FileStore;
use TSF\Transport\ServerFactory;

/**
 * 1v1 对战示例：登录 → 匹配 → 对战 → 结算。
 *
 * 装配顺序有讲究：
 *   1. Gateway 先建好（推送要靠它）；
 *   2. $publish 闭包（房间 → 客户端）依赖 Gateway，排第二；
 *   3. DuelManager 依赖 $publish；
 *   4. MatchService 的配对回调依赖以上两者。
 *
 * 依赖是单向的：房间不认识 Gateway，只拿着一个「把消息发给某个 uid」的闭包。
 * 将来要把房间拆到别的进程，改这一个闭包就行。
 */
function main(): void
{
    $base = __DIR__;
    Bootstrap::boot($base, "app");
    Bootstrap::ensureDirs([$base . "/var/log"]);

    $host = Config::getString("server.host", "0.0.0.0");
    $port = Config::getInt("server.port", 9101);

    $opts = [
        "worker_num" => 1,
        "max_conn" => Config::getInt("server.max_conn", 200),
        "nodelay" => true,
    ];

    $proto = ProtocolStack::fromConfig(
        Config::getString("protocol.frame", "native"),
        Config::getString("protocol.payload", "raw")
    );

    $server = ServerFactory::make("tcp", $host, $port, $proto->frame(), $opts);

    $heartbeat = 30;
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
        true,
        null
    );

    // ---- 房间 → 客户端 ----
    // 房间层不认识 Gateway，只拿这个闭包。
    $publish = function (int $uid, int $msgId, array $payload) use ($gw, $sessions): void {
        $fd = $sessions->fdOf($uid);
        if ($fd <= 0) {
            return; // 已断线：房间照常跑完，只是没人收
        }
        $gw->send($fd, $msgId, (string) json_encode($payload));
    };

    // ---- 对局记录：pkg/db 的端到端示范 ----
    // 这里只建对象、**并没有连库**。Db 是懒连接的，真正的 PDO 在 worker 进程
    // 第一次查询时才建立。这一点是硬要求：Swoole 是 fork 模型，在 fork 之前
    // 连上的 socket 会被所有子进程共享，多个进程同时读写同一个连接会串包。
    $dsn = Config::getString("db.dsn", "sqlite:" . $base . "/var/duel.db");
    $db = new Db($dsn);
    $migrationsDir = $base . "/migrations";

    // ---- 一次性命令：迁移 ----
    // 部署阶段跑一次 `./duel migrate`（或 `tsf migrate .`），跑完就退出；
    // 服务启动只**校验**，不建表。
    //
    // 为什么不放在 workerStart 里：多 worker 会同时 CREATE TABLE。记录表能挡住
    // "重复执行"，挡不住"并发建表"——两个进程同时判断"还没跑过"然后一起 CREATE。
    // 而且建表属于部署动作，每次启动都重算一遍是浪费。
    $cli = isset($_SERVER["argv"][1]) ? (string) $_SERVER["argv"][1] : "";
    if ($cli === "migrate" || $cli === "migrate-status") {
        $code = $cli === "migrate"
            ? runMigrate($db, $migrationsDir, $base)
            : printMigrateStatus($db, $migrationsDir);
        exit($code);
    }

    $records = new Repo(DuelRecord::class, $db);

    // ---- 房间快照：进程被 kill -9 后能把进行中的对局捞回来 ----
    // 每个快照一个文件、原子写（临时文件 + rename），所以不会读到半截 JSON。
    $snapDir = Config::getString("snapshot.dir", $base . "/var/snap");
    $snaps = new FileStore($snapDir);

    // ---- 金币结算：事件日志，而不是数据库 ----
    // 对局记录进 db、金币进日志——这个分界不是随意的：
    //   db 存「当前状态」（这局谁赢、打了几帧），丢了补一条就好；
    //   日志存「发生过的事」（谁该收多少钱），丢了就是账不平。
    // Settle 在构造时重放整份日志重建余额，所以进程被 kill -9 之后余额自动追平。
    // 事件日志**只支持单写入进程**，这里必须先挡住。
    // 原因：`EventLog` 在构造时扫一遍文件定出 nextSeq，两个进程各算一份就会撞号
    // （实测：两进程各写 60 条 → 120 条记录只有 61 个不同序号）。
    // 幂等靠业务 id（`duel:<房间号>`），撞号不会多发钱；但 replay($fromSeq) 和
    // truncate($uptoSeq) 都以 seq 为坐标，一撞就会漏读/重读，归档也跟着错。
    $workers = Config::getInt("server.worker_num", 1);
    if ($workers > 1) {
        throw new \RuntimeException(
            "事件日志只支持单写入进程，但 server.worker_num=" . $workers
            . "。要么把 worker_num 改回 1，要么把日志按 worker 分文件（settle.w{id}.log）再在读取侧合并。"
        );
    }
    $settlePath = Config::getString("settle.path", $base . "/var/settle.log");
    $settle = new Settle(new EventLog($settlePath));

    $duels = new DuelManager($publish, $records, $snaps, $settle);

    // ---- 迁移：启动只校验，不建表 ----
    // 建表是部署动作（`./duel migrate`），这里只回答两个问题：
    //   1. 已执行的迁移文件被改过吗（新环境会得到不同表结构）
    //   2. 还有没执行的迁移吗（说明部署漏了一步）
    // 只让 0 号 worker 查：多 worker 各查一遍除了刷日志没别的作用。
    //
    // 注意这里是**沿用"非核心路径不拦住服务"**的原则：不 fail fast。代价要说清楚——
    // 缺表时每一局战绩都会静默写失败（`recordMatch()` 吞异常），日志里是 error 级别的
    // "未执行的迁移"，但那行字很容易被忽略。真要做到"缺表就别服务"，就把下面两处
    // Logger::error 换成 throw。
    $server->onWorkerStart(function (int $wid) use ($db, $migrationsDir, $duels, $snaps): void {
        if ($wid === 0) {
            try {
                $mig = new Migrator($db, $migrationsDir);

                $bad = $mig->verify();
                if ($bad !== []) {
                    Logger::error("迁移文件与已执行记录不一致（新环境的表结构会不同）", [
                        "problems" => $bad,
                    ]);
                }

                $pending = [];
                foreach ($mig->status() as $row) {
                    if (!$row["applied"]) {
                        $pending[] = $row["version"];
                    }
                }
                if ($pending !== []) {
                    Logger::error("有未执行的迁移，先跑 ./duel migrate", [
                        "worker" => $wid,
                        "pending" => $pending,
                    ]);
                } else {
                    Logger::info("db ready", ["worker" => $wid]);
                }
            } catch (\Throwable $e) {
                Logger::warn("db init failed", ["worker" => $wid, "err" => $e->getMessage()]);
            }
        }

        // 恢复放在 workerStart 而不是 boot() 之前：Service 调度器是在
        // workerStart 里才被定时器驱动的，提前 spawn 出去的消息没人处理
        try {
            $t0 = hrtime(true);
            $stat = $duels->restoreAll($snaps);
            $ms = (int) ((hrtime(true) - $t0) / 1000000);
            if ($stat["done"] > 0 || $stat["skipped"] > 0) {
                Logger::info("rooms restored", [
                    "done" => $stat["done"],
                    "skipped" => $stat["skipped"],
                    "ms" => $ms,
                    "worker" => $wid,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::warn("restore failed", ["worker" => $wid, "err" => $e->getMessage()]);
        }
    });

    // ---- 匹配器：配上就建房 ----
    $matchAddr = Scheduler::instance()->spawnOf(function () use ($duels, $publish): MatchService {
        // 容差给得宽：示例只有两个人，靠等待放宽尽快配上
        $m = new MatchService(500, 500, 100);
        $m->onMatched(function (int $a, int $b) use ($duels, $publish): void {
            $roomId = $duels->create($a, $b);
            if ($roomId === 0) {
                return; // 有人在别的局里，这一对放弃
            }
            $publish($a, Msg::MATCH_FOUND, ["room" => $roomId, "foe" => $b, "side" => 0]);
            $publish($b, Msg::MATCH_FOUND, ["room" => $roomId, "foe" => $a, "side" => 1]);
        });
        return $m;
    });

    // ---- 客户端消息 ----
    $gw->on(Msg::MATCH_JOIN, function (int $fd, int $uid, string $body) use ($matchAddr): void {
        if ($uid <= 0) {
            return; // 没登录
        }
        Scheduler::instance()->deliver($matchAddr, MatchService::MSG_JOIN, [
            "uid" => $uid,
            "score" => 1000,
        ]);
    });

    $gw->on(Msg::MATCH_CANCEL, function (int $fd, int $uid, string $body) use ($matchAddr): void {
        Scheduler::instance()->deliver($matchAddr, MatchService::MSG_LEAVE, (string) $uid);
    });

    // 移动：body 是 "-1" / "0" / "1"
    $gw->on(Msg::MOVE, function (int $fd, int $uid, string $body) use ($duels): void {
        $duels->forward($uid, DuelService::MSG_MOVE, ["uid" => $uid, "dir" => (int) $body]);
    });

    $gw->on(Msg::ATTACK, function (int $fd, int $uid, string $body) use ($duels): void {
        $duels->forward($uid, DuelService::MSG_ATTACK, ["uid" => $uid]);
    });

    // ---- 断线：退出匹配 + 对战判负 ----
    // 不处理的话，掉线玩家的匹配票会一直挂在队列里，对战房间会干等 60 秒超时。
    $gw->onDisconnect(function (int $fd, int $uid) use ($matchAddr, $duels): void {
        Scheduler::instance()->deliver($matchAddr, MatchService::MSG_LEAVE, (string) $uid);
        $duels->forfeit($uid);
    });

    $gw->boot();

    Logger::info("duel ready", ["host" => $host, "port" => $port, "frame" => $proto->frameName()]);
    $server->start();
}

/**
 * `./duel migrate`：把所有未执行的迁移跑完。
 *
 * 用目录当锁（`mkdir` 在 POSIX/Windows 上都是原子的），而不是 `flock`——
 * 后者不在 AOT 的 std 表面里，用了会在编译期或运行期翻车。代价是进程被 `kill -9`
 * 时锁会留下；所以锁里写了 pid 和时间，提示里也写明"确认没有别人在跑就删掉"。
 *
 * 返回退出码：0 成功，1 有迁移失败或连不上库。
 */
function runMigrate(Db $db, string $dir, string $base): int
{
    $lockDir = $base . "/var/migrate.lock";
    if (!@mkdir($lockDir, 0777, true)) {
        $owner = @file_get_contents($lockDir . "/owner.txt");
        echo "migrate 已经在跑，本次退出。" . PHP_EOL;
        echo "  锁目录: " . $lockDir . PHP_EOL;
        echo "  持有者: " . (is_string($owner) ? $owner : "未知（可能是 kill -9 留下的）") . PHP_EOL;
        echo "确认没有别的 migrate 在跑之后，删掉这个目录再试。" . PHP_EOL;
        return 1;
    }
    @file_put_contents($lockDir . "/owner.txt", "pid=" . posix_getpid() . " at=" . date("Y-m-d H:i:s"));

    $code = 0;
    try {
        $mig = new Migrator($db, $dir);
        $r = $mig->up();

        echo "migrate: 执行 " . count($r["executed"]) . " 个，跳过 " . $r["skipped"]
            . " 个，失败 " . count($r["failed"]) . " 个（" . $r["shards"] . " 个分片）" . PHP_EOL;
        foreach ($r["executed"] as $v) {
            echo "  + " . $v . PHP_EOL;
        }
        if ($r["failed"] !== []) {
            foreach ($r["failed"] as $f) {
                echo "  ! " . $f . PHP_EOL;
            }
            $code = 1;
        }

        // 跑完立刻自检：迁移文件在"执行之后被改过"是最常见的坑
        $bad = $mig->verify();
        if ($bad !== []) {
            foreach ($bad as $b) {
                echo "  ! 版本 " . $b["version"] . ": " . $b["why"] . PHP_EOL;
            }
            $code = 1;
        }
    } catch (\Throwable $e) {
        echo "migrate 失败: " . $e->getMessage() . PHP_EOL;
        $code = 1;
    }

    // 不用 finally：编译代码里没验证过，这里两条路径都显式释放
    @unlink($lockDir . "/owner.txt");
    @rmdir($lockDir);
    return $code;
}

/** `./duel migrate-status`：只读地列出迁移状态，给 CI / 排障用 */
function printMigrateStatus(Db $db, string $dir): int
{
    try {
        $mig = new Migrator($db, $dir);
        $rows = $mig->status();
        $pending = 0;
        foreach ($rows as $row) {
            $state = $row["applied"] ? "applied" : "PENDING";
            if (!$row["applied"]) {
                $pending++;
            }
            $sum = $row["checksum_ok"] ? "" : "  (文件已被改动)";
            echo "  " . $row["version"] . "_" . $row["name"] . "  " . $state . $sum . PHP_EOL;
        }
        echo "共 " . count($rows) . " 个迁移，未执行 " . $pending . " 个" . PHP_EOL;
        return $pending === 0 ? 0 : 1;
    } catch (\Throwable $e) {
        echo "读取迁移状态失败: " . $e->getMessage() . PHP_EOL;
        return 1;
    }
}
