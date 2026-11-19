<?php

/**
 * 战斗服验证客户端：N 个并发连接，走真实 TCP。
 *
 * 为什么不用 ab / wrk：验证的不是吞吐，而是"同一张地图里 N 个人互相看得见、
 * 打得中"。这要求每个连接有自己的登录态、进图态和动作节奏，
 * HTTP 压测工具做不到这件事。
 *
 * 用法：
 *   php85 bench.php [端口] [连接数] [秒数] [secret] [uid起点] [进程数]
 *   php85 bench.php 9002 200 12                    # 单进程 200 连接
 *   php85 bench.php 9002 1000 15 "" 5000 5         # 5 进程共 1000 连接
 *
 * 为什么要多进程：PHP 单进程用 stream_select 管 200 个连接已经要在建连阶段
 * 花十几秒（每个 fd 都是非阻塞的，但建连是同步的）。跑到 1000 连接时，
 * 客户端自己就成了瓶颈 —— 压出来的"服务端 p99"其实是客户端的调度延迟。
 * 分成 5 进程 × 200 连接，每个进程的负担与单进程 200 连接一致。
 *
 * 每个连接做三件事：
 *   1. 用 HMAC 签一个自己的 token 登录（不依赖配置表里的静态 token）
 *   2. 进图，然后每 100ms 随机"移动"或"放火球"
 *   3. 统计收到的快照/伤害/拒绝
 *
 * 关键指标看服务端日志里的 `battle stats`：tick 分位、AOI 候选数、命中数。
 * 客户端只能证明"功能是通的"，证明不了"帧预算够用"。
 */

require_once __DIR__ . "/../../tsf/src/Protocol/Contract/DecodedFrame.php";
require_once __DIR__ . "/../../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../../tsf/src/Protocol/Frame/HeaderFrame.php";

use TSF\Protocol\Frame\HeaderFrame;

const MSG_LOGIN = 1;
const MSG_LOGIN_OK = 2;
const MSG_ENTER = 6001;
const MSG_MOVE = 6002;
const MSG_CAST = 6003;
const MSG_ENTER_OK = 6100;
const MSG_SNAPSHOT = 6300;
const MSG_DAMAGE = 6301;
const MSG_REJECT = 6303;

/**
 * 跑一批连接，返回统计。
 *
 * @return array<string, float|int>
 */
function runBench(int $port, int $want, int $seconds, string $secret, int $uidBase): array
{
    $conns = [];
    $stats = [
        "connected" => 0,
        "login_ok" => 0,
        "enter_ok" => 0,
        "snapshots" => 0,
        "damage" => 0,
        "reject" => 0,
        "events" => 0,
        "move_sent" => 0,
        "cast_sent" => 0,
        "view_sum" => 0,
        "dmg_n" => 0,
        "dmg_sum" => 0,
        "dmg_min" => 0,
        "dmg_max" => 0,
    ];

    for ($i = 0; $i < $want; $i++) {
        $uid = $uidBase + $i + 1;
        $sock = @stream_socket_client("tcp://127.0.0.1:" . $port, $errno, $errstr, 3);
        if ($sock === false) {
            continue;
        }
        stream_set_blocking($sock, false);
        $token = $uid . "." . hash_hmac("sha256", (string) $uid, $secret);
        $id = (int) $sock;
        // buf 必须放在每连接状态里而不是函数 static：static 会把 A 的
        // 半包和 B 的半包混在一起，表现是"偶发解析错乱"
        $conns[$id] = [
            "sock" => $sock,
            "uid" => $uid,
            "buf" => "",
            "state" => 0,
            "next" => 0,
        ];
        fwrite($sock, HeaderFrame::build(MSG_LOGIN, $token));
        $stats["connected"]++;
    }

    $start = microtime(true);
    $end = $start + $seconds;

    while (microtime(true) < $end && $conns !== []) {
        $read = [];
        foreach ($conns as $c) {
            $read[] = $c["sock"];
        }
        $w = null;
        $e = null;
        $ready = @stream_select($read, $w, $e, 0, 20000);

        if ($ready !== false && $ready > 0) {
            foreach ($read as $sock) {
                $id = (int) $sock;
                if (!isset($conns[$id])) {
                    continue;
                }
                $chunk = @fread($sock, 65536);
                if ($chunk === false || $chunk === "") {
                    if (feof($sock)) {
                        @fclose($sock);
                        unset($conns[$id]);
                    }
                    continue;
                }
                $conns[$id]["buf"] .= $chunk;

                // 一次读到多帧：循环拆，直到拆不出完整帧
                while (true) {
                    $f = HeaderFrame::parse($conns[$id]["buf"]);
                    if ($f === null) {
                        break;
                    }
                    $conns[$id]["buf"] = substr($conns[$id]["buf"], $f->consumed);
                    $stats["events"]++;
                    $msgId = $f->msgId;

                    if ($msgId === MSG_LOGIN_OK) {
                        $stats["login_ok"]++;
                        $conns[$id]["state"] = 1;
                        fwrite($conns[$id]["sock"], HeaderFrame::build(MSG_ENTER, "{}"));
                        continue;
                    }
                    if ($msgId === MSG_ENTER_OK) {
                        $stats["enter_ok"]++;
                        $conns[$id]["state"] = 2;
                        $conns[$id]["next"] = microtime(true);
                        continue;
                    }
                    if ($msgId === MSG_SNAPSHOT) {
                        $stats["snapshots"]++;
                        // 快照是元组：[uid, frame, [[uid,x,y,hp], ...]]
                        $d = json_decode($f->body, true);
                        if (is_array($d) && isset($d[2]) && is_array($d[2])) {
                            $stats["view_sum"] += count($d[2]);
                        }
                        continue;
                    }
                    if ($msgId === MSG_DAMAGE) {
                        $stats["damage"]++;
                        // 事件包：[1, from, x, y, [[uid,dmg,hp],...]] 技能结算
                        //         [2, uid, x, y, hp]                复活
                        // 一次施法一条消息，命中目标在末尾的列表里
                        $d = json_decode($f->body, true);
                        if (is_array($d) && isset($d[0]) && ((int) $d[0]) === 1) {
                            $targets = $d[4] ?? null;
                            if (is_array($targets)) {
                                foreach ($targets as $t) {
                                    if (!is_array($t) || !isset($t[1])) {
                                        continue;
                                    }
                                    $v = (int) $t[1];
                                    if ($v <= 0) {
                                        continue;
                                    }
                                    $stats["dmg_n"]++;
                                    $stats["dmg_sum"] += $v;
                                    if ($stats["dmg_min"] === 0 || $v < $stats["dmg_min"]) {
                                        $stats["dmg_min"] = $v;
                                    }
                                    if ($v > $stats["dmg_max"]) {
                                        $stats["dmg_max"] = $v;
                                    }
                                }
                            }
                        }
                        continue;
                    }
                    if ($msgId === MSG_REJECT) {
                        $stats["reject"]++;
                    }
                }
            }
        }

        // 动作节奏：每 100ms 一次
        $now = microtime(true);
        foreach ($conns as $id => $c) {
            if ($c["state"] !== 2 || $c["next"] > $now) {
                continue;
            }
            $conns[$id]["next"] = $now + 0.1;
            $sock = $c["sock"];
            if (mt_rand(0, 99) < 45) {
                $dx = mt_rand(-1, 1);
                $dy = mt_rand(-1, 1);
                fwrite($sock, HeaderFrame::build(MSG_MOVE, '{"dx":' . $dx . ',"dy":' . $dy . "}"));
                $stats["move_sent"]++;
            } else {
                fwrite($sock, HeaderFrame::build(MSG_CAST, '{"skill":1}'));
                $stats["cast_sent"]++;
            }
        }
    }

    foreach ($conns as $c) {
        @fclose($c["sock"]);
    }
    $stats["elapsed"] = microtime(true) - $start;
    $stats["online"] = count($conns);
    return $stats;
}

/** @param array<int, array<string, float|int>> $list */
function summarize(array $list): void
{
    $sum = [];
    $keys = ["connected", "login_ok", "enter_ok", "snapshots", "damage", "reject", "events", "move_sent", "cast_sent", "view_sum", "dmg_n", "dmg_sum"];
    foreach ($keys as $k) {
        $sum[$k] = 0;
    }
    $sum["dmg_min"] = 0;
    $sum["dmg_max"] = 0;
    $elapsed = 0.0;

    foreach ($list as $s) {
        foreach ($keys as $k) {
            $sum[$k] += (int) ($s[$k] ?? 0);
        }
        $mn = (int) ($s["dmg_min"] ?? 0);
        if ($mn > 0 && ($sum["dmg_min"] === 0 || $mn < $sum["dmg_min"])) {
            $sum["dmg_min"] = $mn;
        }
        $mx = (int) ($s["dmg_max"] ?? 0);
        if ($mx > $sum["dmg_max"]) {
            $sum["dmg_max"] = $mx;
        }
        $elapsed = max($elapsed, (float) ($s["elapsed"] ?? 0));
    }

    $viewAvg = $sum["snapshots"] > 0 ? $sum["view_sum"] / $sum["snapshots"] : 0;
    $dmgAvg = $sum["dmg_n"] > 0 ? $sum["dmg_sum"] / $sum["dmg_n"] : 0;
    $castOk = $sum["cast_sent"] - $sum["reject"];

    echo PHP_EOL . "===== 客户端汇总 =====" . PHP_EOL;
    printf("时长          : %.1fs%s\n", $elapsed, count($list) > 1 ? "（" . count($list) . " 进程）" : "");
    printf("建连/登录/进图 : %d / %d / %d\n", $sum["connected"], $sum["login_ok"], $sum["enter_ok"]);
    printf("发包          : move=%d cast=%d\n", $sum["move_sent"], $sum["cast_sent"]);
    printf("收包          : 快照=%d 伤害=%d 拒绝=%d 总计=%d\n", $sum["snapshots"], $sum["damage"], $sum["reject"], $sum["events"]);
    printf("平均视野人数  : %.1f\n", $viewAvg);
    printf("施法成功数    : %d\n", $castOk);
    printf("伤害值        : n=%d min=%d max=%d avg=%.1f\n", $sum["dmg_n"], $sum["dmg_min"], $sum["dmg_max"], $dmgAvg);
}

$port = (int) ($argv[1] ?? 9002);
$want = (int) ($argv[2] ?? 50);
$seconds = (int) ($argv[3] ?? 10);
$secret = (string) ($argv[4] ?? "");
$uidBase = (int) ($argv[5] ?? 5000);
$procs = (int) ($argv[6] ?? 1);
if ($secret === "") {
    $secret = "275445b16e940be9f7c890a24864325c";
}
if ($procs < 1) {
    $procs = 1;
}

// 单进程：直接跑，不 fork，避免多一层开销和临时文件
if ($procs === 1) {
    summarize([runBench($port, $want, $seconds, $secret, $uidBase)]);
    exit(0);
}

// 多进程：每个子进程跑一段独立的 uid 区间，结果写临时文件由父进程汇总
$per = (int) ceil($want / $procs);
$pids = [];
$files = [];
for ($p = 0; $p < $procs; $p++) {
    $file = "/tmp/bench_tsf_" . getmypid() . "_" . $p . ".json";
    $files[] = $file;
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "fork failed" . PHP_EOL);
        continue;
    }
    if ($pid === 0) {
        // 子进程：跑完写结果，直接退出（不要 return，否则会回到父进程逻辑）
        $r = runBench($port, $per, $seconds, $secret, $uidBase + $p * $per);
        file_put_contents($file, json_encode($r));
        exit(0);
    }
    $pids[] = $pid;
}

$status = 0;
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$all = [];
foreach ($files as $file) {
    if (is_file($file)) {
        $raw = file_get_contents($file);
        @unlink($file);
        $d = json_decode((string) $raw, true);
        if (is_array($d)) {
            $all[] = $d;
        }
    }
}
summarize($all);
