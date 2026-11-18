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
            "同一个进程内的多 worker 不支持，但**多进程可以**：server.worker_num=" . $workers . "。"
            . "Swoole 的 worker 之间没有共享调度器，每个 worker 一份服务表，"
            . "在进程内「协作」等于把单进程的正确性拆成 N 份互不知情的事实。"
            . "要多开就起多个进程：每个进程 worker_num=1 + 各自不同的 server.node_id 和 server.port。"
            . "（`run-cluster.sh` 就是这么起的。）"
        );
    }
    // ---- 事件日志：每个节点一个文件，读侧收全部 ----
    //
    // 写：只写自己的 `settle.n<id>.log`。房间归属保证同一个房间只由一个节点写，
    //     所以不需要多写者协调（这也是"全局序不是需求"的根据）。
    // 读：扫 var/ 下所有 `settle*.log`（含历史的单文件 `settle.log`）归并。
    //
    // 老文件不迁移：`settle.log` 继续被读，新写入进分段文件。
    // 好处是升级不需要停机搬数据，坏处是同一个逻辑日志会横跨两代文件——
    // 归并按 (t, 文件序号, s) 排序，跨代的顺序仍然确定。
    $nodeId = Config::getInt("server.node_id", 0);
    if ($nodeId < 0 || $nodeId > 255) {
        throw new \RuntimeException("server.node_id 必须在 0..255，当前 " . $nodeId);
    }

    // ---- 节点身份锁：同一个 node_id 只能有一个进程 ----
    //
    // 这是多进程下最要命的一条：两个进程用同一个 node_id，就是**同一个号段有两个主人**，
    // 会各自建出同一个房间号、各自往同一个快照文件写、各自的 `duel:<号>` 结算互相判重——
    // 也就是设计里最怕的"双主"，而且它不会报错，只会让账慢慢对不上。
    //
    // 用 mkdir 做原子占位（和 Migrator 的锁同一招：`flock` 不在 AOT 的 std 表面里）。
    // 锁目录里写 pid，启动时若发现 pid 还活着就拒绝；死了（kill -9 留下的残骸）就接管。
    // 只读命令不抢锁：服务在跑的时候，运维仍然要能查账（`wallet-check`）和看迁移状态。
    // 会**写**的命令（wallet-debit / wallet-credit）必须抢锁——它们写的就是本节点那份日志，
    // 和正在跑的服务同时写就等于两个写者，违反"每个日志文件只有一个写入进程"的前提。
    // （真要在服务运行时扣款，走 §T6-05 的 GM 通道：它和 Scheduler 同一个事件循环，天然单写者。）
    // drain 也算只读：它只写一个标记文件，不碰房间状态、不碰事件日志。
    // 否则"通知正在跑的服务退役"这件事会被服务自己的锁挡住。
    $readOnly = $cli === "wallet-check" || $cli === "migrate"
        || $cli === "migrate-status" || $cli === "drain";
    $lockDir = $base . "/var/node" . $nodeId . ".lock";
    $lockPidFile = $lockDir . "/pid";
    if (!$readOnly && !@mkdir($lockDir, 0777, true)) {
        $holder = 0;
        $raw = @file_get_contents($lockPidFile);
        if (is_string($raw)) {
            $holder = (int) trim($raw);
        }
        $alive = $holder > 0 && is_dir("/proc/" . $holder);
        if ($alive) {
            throw new \RuntimeException(
                "node_id=" . $nodeId . " 已被进程 " . $holder . " 占用。"
                . "同一个 node_id 只能有一个进程——两个进程共用一个号段就是两个主人，"
                . "会各自建出同一个房间号（双主）。请给每个进程配不同的 server.node_id。"
            );
        }
        // 上一个是 kill -9 走的，锁是残骸：清掉接管
        @unlink($lockPidFile);
        @rmdir($lockDir);
        if (!@mkdir($lockDir, 0777, true)) {
            throw new \RuntimeException("无法获取 node_id=" . $nodeId . " 的锁：" . $lockDir);
        }
    }
    if (!$readOnly) {
        @file_put_contents($lockPidFile, (string) getmypid());
    }

    $settlePath = Config::getString(
        "settle.path",
        $base . "/var/settle.n" . $nodeId . ".log"
    );

    $varDir = $base . "/var";
    $readerLogs = [];
    $names = @scandir($varDir);
    if (is_array($names)) {
        sort($names);   // 稳定顺序：MergedLog 靠"文件序号"打破同 t 的平手
        foreach ($names as $name) {
            $name = (string) $name;
            if (str_starts_with($name, "settle") && str_ends_with($name, ".log")) {
                $readerLogs[] = new EventLog($varDir . "/" . $name);
            }
        }
    }
    $ownLog = new EventLog($settlePath);
    if ($readerLogs === []) {
        $readerLogs = [$ownLog];
    }
    $settle = new Settle($ownLog, null, $readerLogs);

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
    if ($cli === "drain") {
        exit(runDrain($base . "/var/drain.n" . $nodeId, $nodeId));
    }

    // 节点号在 settle 构造之前就算好了（日志文件按它命名，见上）。
    // 多进程时每个进程配不同的 node_id：号段互不重叠，日志文件也各自独立。
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
    // drain 的两个触发方式：
    //   - SIGTERM（标准运维接口，容器/k8s 都发这个）
    //   - var/drain.n<id> 文件（没有信号通道时用；秒级轮询）
    // 两个都只是"进入 drain"，真正的退出由下面的 1s 定时器判断"存量做完了没"。
    $drainFile = $base . "/var/drain.n" . $nodeId;
    $drainTimeoutMs = Config::getInt("server.drain_timeout_sec", 300) * 1000;

    // master 侧收 SIGTERM：**只写 drain 文件，不退出**。
    //
    // 为什么必须挂在 master 的 Start 上：运维/k8s 发 SIGTERM 打的是 master（容器里是 PID 1）。
    // 在 worker 里注册 handler 管不到 master——master 按默认行为直接退出，worker 连带被杀，
    // drain 根本没机会执行（第一版就是这么失败的：SIGTERM 一来进程立刻没了）。
    //
    // 退出由 worker 判断"存量做完了没"来触发（下面那个 1s 定时器），
    // master 只负责把"有人要求你退役"这件事传下去。
    // drain 的触发方式是**文件**，不是信号。
    //
    // 试过两次让 master 收 SIGTERM：`\Swoole\Process::signal(SIGTERM, ...)` 在
    // master 顶层注册、以及在 `on('Start')` 回调里注册，两次都返回成功但 **handler 从不触发**
    // ——SWOOLE_PROCESS 模式下 master 的 SIGTERM 由 Swoole 自己接管，用户 handler 轮不到。
    // 所以不跟它较劲：运维写 var/drain.n<id>，worker 每秒轮询到就开始退役。
    // k8s 场景用 preStop hook 执行 `./duel drain`（见 demo/README.md）。

    $server->onWorkerStart(function (int $wid) use ($db, $migrationsDir, $duels, $snaps, $settle, $server, $drainFile, $drainTimeoutMs): void {
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

            // ---- drain：优雅退役 ----
            // 只有 0 号 worker 需要（worker_num 恒为 1，多节点是多个进程）。
            // 触发靠轮询 drain 文件——master 的 SIGTERM 被 Swoole 接管，用户 handler 不触发（实测两次）。
            // 回调**必须**声明 `int $timerId` 参数：AOT 下闭包的函数签名是固定的，
            // Swoole 调定时器回调时会传 timerId，签名为空参的闭包在这里静默失败——
            // 表现是"定时器注册成功但一次都不触发"（踩过：探针文件始终不出现）。
            // 框架自己的 1ms tick 也是这么声明的。
            \Swoole\Timer::tick(1000, function (int $timerId) use ($duels, $server, $drainFile, $drainTimeoutMs): void {
                if (is_file($drainFile)) {
                    beginNodeDrain($duels, "drain file");
                }
                if (!$duels->isDraining()) {
                    return;
                }
                $left = $duels->count();
                if ($left === 0) {
                    // 只请求一次：Swoole 的 shutdown 是异步的，定时器还会再跳几次，
                    // 不设闸就会连着打好几遍 drain done（日志上看像是重复退役）
                    if ($duels->requestShutdownOnce()) {
                        Logger::info("drain done, shutting down", [
                            "ms" => $duels->drainElapsedMs(),
                        ]);
                        $server->shutdown();
                    }
                    return;
                }
                if ($duels->drainElapsedMs() > $drainTimeoutMs) {
                    // 超时兜底：继续等下去就是"这个进程永远不退"，
                    // 而它的房间已经很可能是死局（玩家早掉线了）。宁可丢一局，也别卡住发布
                    Logger::error("drain timeout, forcing shutdown", [
                        "duels_left" => $left,
                        "ms" => $duels->drainElapsedMs(),
                        "timeout_ms" => $drainTimeoutMs,
                    ]);
                    $server->shutdown();
                }
            });

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
    $gw->on(Msg::MATCH_JOIN, function (int $fd, int $uid, string $body) use ($matchAddr, $duels, $gw): void {
        if ($uid <= 0) {
            return; // 没登录
        }
        // 本节点正在退役：**明确回绝**。静默不配对会让玩家以为卡了、反复重试，
        // 而排进去的对局也会在 create() 那里被拒，白等一轮
        if ($duels->isDraining()) {
            // 这个 demo 的协议是 `payload: raw`，所以载荷**必须是字符串**。
            // 传数组会让 `Gateway::send()` 抛 "payload=raw 时业务消息必须是 string"，
            // 而 handler 外面有 try/catch —— 异常被吞掉，玩家看不到任何回绝、只会一直等。
            // （踩过一次：日志里连"拒绝"都没有，只有客户端 timeout。）
            $gw->send($fd, Msg::NOTICE, json_encode([
                "code" => "draining",
                "text" => "本服正在维护，请稍后再试",
            ]));
            return;
        }
        Scheduler::instance()->deliver($matchAddr, MatchService::MSG_JOIN, [
            "uid" => $uid,
            "score" => 1000,
        ]);
    });

    $gw->on(Msg::MATCH_CANCEL, function (int $fd, int $uid, string $body) use ($matchAddr): void {
        Scheduler::instance()->deliver($matchAddr, MatchService::MSG_LEAVE, (string) $uid);
    });

    // ---- 重连找回 ----
    // 客户端重连 → 重新登录（Gateway 会把 uid→fd 更新成新连接）→ 发 REJOIN。
    // 房间那边的推送走 `$publish`，它按 uid 查 fd，所以**不需要**通知房间换连接；
    // 这里只负责：撤销待判负、把房间号/对手/边告诉客户端，让它接着画。
    $gw->on(Msg::REJOIN, function (int $fd, int $uid, string $body) use ($duels, $gw): void {
        if ($uid <= 0) {
            return; // 没登录
        }
        $info = $duels->roomOf($uid);
        if ($info === []) {
            // payload: raw → 必须是字符串
            $gw->send($fd, Msg::NOTICE, json_encode([
                "code" => "no-room",
                "text" => "没有进行中的对局",
            ]));
            return;
        }
        $duels->cancelForfeit($uid);
        Logger::info("player rejoined", [
            "uid" => $uid,
            "room" => $info["room"],
        ]);
        $gw->send($fd, Msg::REJOIN_OK, json_encode($info));
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
        // 断线**不立刻判负**：给宽限期，期间重连就不算输（`Msg::REJOIN`）。
        // 立刻判负等于"网络抖一下白输一局"，对局类玩法的体验事故。
        // 宽限期可配：对局越"重"，给的时间应该越长（一次掉线代价太大）
        $graceMs = Config::getInt("server.rejoin_grace_sec", 15) * 1000;
        if ($duels->scheduleForfeit($uid, $graceMs)) {
            Logger::info("player disconnected, forfeit in grace", [
                "uid" => $uid,
                "grace_ms" => $graceMs,
            ]);
        }
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
 * 运维命令：通知本节点退役（写 drain 标记文件，由进程自己轮询）。
 *
 * 为什么不用 SIGTERM：SWOOLE_PROCESS 模式下 master 的 SIGTERM 由 Swoole 接管，
 * 用户注册的 handler 不触发（顶层与 Start 回调都试过）。文件触发不依赖信号通道，
 * 容器里、跳板机上、`docker exec` 里都能用。
 */
function runDrain(string $file, int $nodeId): int
{
    $ok = @file_put_contents($file, (string) time());
    if ($ok === false) {
        echo "ERROR   写不了 " . $file . "（目录不存在或只读）" . PHP_EOL;
        return 1;
    }
    echo "OK      已通知 node " . $nodeId . " 退役（" . $file . "）" . PHP_EOL;
    echo "        服务会在存量房间打完后自己退出；进度看日志里的 drain started / drain done。" . PHP_EOL;
    echo "        注意：要跟目标进程用同一个 node_id 执行本命令（TSF_ENV=node1 ./duel drain）。" . PHP_EOL;
    return 0;
}

/**
 * 让本节点进入 drain：不再建新房，继续服务存量，等它自己打完。
 *
 * 幂等：重复调用只记一次。
 */
function beginNodeDrain(DuelManager $duels, string $why): void
{
    if ($duels->isDraining()) {
        return;
    }
    $duels->beginDrain();
    Logger::info("drain started", [
        "why" => $why,
        "duels" => $duels->count(),
    ]);
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
