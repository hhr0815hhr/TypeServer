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
        // 以前这里写死 1，**配置里的 server.worker_num 被静默忽略**——
        // 调了 worker_num 发现行为没变，会以为是 Swoole 的问题。
        // 现在跟其他选项一样从配置读；多 worker 的正确性还没验（见下面的断言）。
        "worker_num" => Config::getInt("server.worker_num", 1),
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
    // 多 worker 目前**整体**不支持，不是"事件日志一条"的问题。实测三处：
    //
    //  ① 快照目录被每个 worker 各扫一遍。一个快照、两个 worker 启动，日志里会出现两次
    //     `rooms restored done=1`（worker 0 与 worker 1），同一个房间在两个调度器里各建一份，
    //     并且都往同一个 `duel_<id>.snap` 写——后写的覆盖先写的，一份进度静默丢失。
    //  ② 事件日志的 nextSeq 是构造时扫文件算出来的，多进程会撞号（实测 120 条 61 个序号）。
    //  ③ 房间号是每个 worker 各自从 1 数的，跨 worker 必然重复；而结算幂等键是
    //     `duel:<房间号>`，重复就意味着"钱静默不发"。
    //
    // 要支持多 worker，缺的是「房间归属」这一层设计：谁负责哪些房间、重启后怎么认领、
    // 跨 worker 怎么路由。不是把 1 改成 4 的事。所以这里直接拒绝，别让它半死不活地跑。
    $workers = Config::getInt("server.worker_num", 1);
    if ($workers > 1) {
        throw new \RuntimeException(
            "这个 demo 只支持单 worker，但 server.worker_num=" . $workers . "。"
            . "实测三处缺口：①快照被每个 worker 各恢复一次，同一房间会有两份状态；"
            . "②事件日志多进程写会撞号；③房间号各 worker 各数一份，跨 worker 重复会让结算被跳过。"
            . "要支持多 worker 得先设计「房间归属」（谁负责哪些房间、重启后怎么认领、跨 worker 怎么路由）。"
        );
    }
    $settlePath = Config::getString("settle.path", $base . "/var/settle.log");
    $settle = new Settle(new EventLog($settlePath));

    // ---- 运维命令：钱的入口 ----
    // 和 migrate 一样，跑完就退出、不起服务。
    //
    // 为什么扣款做成命令而不是走游戏协议：这个 demo 没有商城/复活/押金这些消耗场景，
    // 硬造一个只会变成假业务。但"运维扣款/补偿"是真实存在的动作——退款、活动补发、
    // 客服手工调整——而且天然需要一个"人能手工执行、能把结果贴到工单里"的入口。
    // 真实业务（商城、抽卡）接进来时的用法见 `tsf/README.md` §25.6。
    if ($cli === "wallet-debit" || $cli === "wallet-credit") {
        exit(runWallet($settle, $cli, $_SERVER["argv"] ?? []));
    }
    if ($cli === "wallet-check") {
        exit(runWalletCheck($settle));
    }

    // 节点号：房间号按它分段编址（`RoomIds`）。单机保持 0，号还是 1,2,3……
    // 多进程时每个进程配不同的 node_id，各自的号段互不重叠。
    $nodeId = Config::getInt("server.node_id", 0);
    $duels = new DuelManager($publish, $records, $snaps, $settle, $nodeId);

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
    $server->onWorkerStart(function (int $wid) use ($db, $migrationsDir, $duels, $snaps, $settle): void {
        if ($wid === 0) {
            // 对账：独立重放一遍日志，和内存账本逐人比对。
            // 这一步会**再读一遍整份日志**（Settle 构造时已经重放过一次），
            // 日志很大时是双倍启动成本——所以它和迁移校验一样只在 0 号 worker 跑一次，
            // 而且"账不平"只记 error 不拦服务（和非核心路径的一贯取舍一致）。
            $rep = $settle->reconcile();
            if ($rep["ok"]) {
                Logger::info("wallet reconciled", [
                    "events" => $rep["events"],
                    "players" => $rep["players"],
                    "total" => $rep["total_log"],
                ]);
            } else {
                Logger::error("钱包对账不平", [
                    "diff" => array_slice($rep["diff"], 0, 10),
                    "diff_count" => count($rep["diff"]),
                    "negative" => $rep["negative"],
                    "dup_ids" => array_slice($rep["dup_ids"], 0, 10),
                    "bad_debits" => array_slice($rep["bad_debits"], 0, 10),
                    "total_mem" => $rep["total_mem"],
                    "total_log" => $rep["total_log"],
                ]);
            }

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

/**
 * 运维命令：扣款 / 充值。
 *
 * ```
 * ./duel wallet-debit  1001 50 order-20261116-001
 * ./duel wallet-credit 1001 50 refund-20261116-001
 * ```
 *
 * 退出码：0 记账成功；2 业务拒绝（余额不足 / 这笔已扣过 / 参数非法）；1 用法错误。
 * 拒绝和错误分开，是为了让调用方能区分"重试没用"和"命令写错了"。
 *
 * 这里的几个预检**只是为了把原因说清楚**，真正的判断在 `Settle::debit()` 里
 * （它自己会再查一次——多一层，但账这种事宁可多查一遍）。
 *
 * @param array<int, string> $argv
 */
function runWallet(Settle $settle, string $cli, array $argv): int
{
    $uid = isset($argv[2]) ? (int) $argv[2] : 0;
    $amount = isset($argv[3]) ? (int) $argv[3] : 0;
    $ref = isset($argv[4]) ? (string) $argv[4] : "";

    if ($uid <= 0 || $amount <= 0 || $ref === "") {
        echo "用法: ./duel " . $cli . " <uid> <amount> <ref>" . PHP_EOL;
        echo "  ref 是幂等键，必须是业务上唯一的东西（订单号/工单号）；" . PHP_EOL;
        echo "  用随机值等于放弃幂等——重放时会重复记账。" . PHP_EOL;
        return 1;
    }

    $isDebit = $cli === "wallet-debit";

    if ($isDebit && $settle->isDebited($ref)) {
        echo "SKIP    ref=" . $ref . " 已扣过（幂等），当前余额 " . $settle->balanceOf($uid) . PHP_EOL;
        return 2;
    }
    if ($isDebit && !$settle->wallet()->has($uid, $amount)) {
        echo "REJECT  余额不足：uid=" . $uid . " 需要 " . $amount
            . "，当前 " . $settle->balanceOf($uid) . PHP_EOL;
        return 2;
    }

    try {
        $ok = $isDebit
            ? $settle->debit($uid, $amount, $ref)
            : $settle->credit($uid, $amount, $ref);
    } catch (\Throwable $e) {
        // 事件日志写不进去（磁盘满、目录只读）：这是环境问题，不是业务拒绝
        echo "ERROR   记账失败，钱没有变动: " . $e->getMessage() . PHP_EOL;
        return 1;
    }

    if (!$ok) {
        echo "REJECT  未记账（参数非法或已处理过）" . PHP_EOL;
        return 2;
    }

    echo ($isDebit ? "OK      debit  " : "OK      credit ") . "uid=" . $uid
        . " amount=" . $amount . " ref=" . $ref
        . " 余额=" . $settle->balanceOf($uid) . PHP_EOL;
    return 0;
}

/**
 * 运维命令：对账。
 *
 * 独立重放日志、和内存账本逐人比对。退出码 0 = 平，3 = 不平（可以挂到巡检脚本里）。
 */
function runWalletCheck(Settle $settle): int
{
    $rep = $settle->reconcile();

    echo "事件 " . $rep["events"] . " 条，玩家 " . $rep["players"] . " 个"
        . "，内存合计 " . $rep["total_mem"] . "，日志合计 " . $rep["total_log"] . PHP_EOL;

    if ($rep["ok"]) {
        echo "OK      账平" . PHP_EOL;
        return 0;
    }

    echo "FAIL    对账不平" . PHP_EOL;
    foreach ($rep["diff"] as $d) {
        echo "   uid " . $d["uid"] . "：内存 " . $d["mem"] . " / 日志 " . $d["log"] . PHP_EOL;
    }
    foreach ($rep["negative"] as $uid) {
        echo "   负数余额 uid " . $uid . PHP_EOL;
    }
    foreach ($rep["dup_ids"] as $id) {
        echo "   重复事件 id " . $id . PHP_EOL;
    }
    foreach ($rep["bad_debits"] as $id) {
        echo "   重放时余额不足的扣款 " . $id . PHP_EOL;
    }
    return 3;
}
