<?php

// 1v1 对战客户端。用法：php85 client.php <port> <token> [aggressive|passive]
//
// 只依赖协议的"契约 + 头实现"三个文件，不需要传输层/Gateway——
// 客户端和服务器用的是同一份帧编解码，所以不存在"两边格式不一致"这类问题。

require_once "/data/TypeServer/tsf/src/Protocol/Contract/DecodedFrame.php";
require_once "/data/TypeServer/tsf/src/Protocol/Contract/FrameCodec.php";
require_once "/data/TypeServer/tsf/src/Protocol/Frame/HeaderFrame.php";

use TSF\Protocol\Frame\HeaderFrame;

const MSG_LOGIN = 1;
const MSG_LOGIN_OK = 2;
const MSG_MATCH_JOIN = 5001;
const MSG_MATCH_FOUND = 5100;
const MSG_REJOIN = 5003;
const MSG_REJOIN_OK = 5101;
const MSG_MOVE = 5201;
const MSG_ATTACK = 5202;
const MSG_STATE = 5300;
const MSG_RESULT = 5301;

// 服务端的一句话通知（本服 drain/维护时拒绝匹配）
const MSG_NOTICE = 5400;

const ATTACK_RANGE = 1500;
const ATTACK_CD_MS = 800;

$port = (int) ($argv[1] ?? 9101);
$token = (string) ($argv[2] ?? "tok-1001");
$mode = (string) ($argv[3] ?? "aggressive");
// --rejoin：登录后不发匹配，直接找回自己那一局（模拟断线重连）
$rejoin = isset($argv[4]) && $argv[4] === "--rejoin";

$sock = stream_socket_client("tcp://127.0.0.1:" . $port, $errno, $errstr, 3);
if ($sock === false) {
    fwrite(STDERR, "connect failed: " . $errstr . PHP_EOL);
    exit(1);
}
stream_set_blocking($sock, false);

/**
 * 读一帧，超时返回 null。
 * 缓冲区 static 保留：一次读到多帧时后面的不会丢。
 */
function readFrame($sock, float $timeout = 1.0): ?array
{
    static $buf = "";
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $f = HeaderFrame::parse($buf);
        if ($f !== null) {
            $buf = substr($buf, $f->consumed);
            return [$f->msgId, $f->body];
        }
        $chunk = fread($sock, 65536);
        if ($chunk === false) {
            break;
        }
        if ($chunk === "") {
            if (feof($sock)) {
                return null;
            }
            usleep(2000);
            continue;
        }
        $buf .= $chunk;
    }
    return null;
}

function send($sock, int $msgId, string $body = ""): void
{
    fwrite($sock, HeaderFrame::build($msgId, $body));
}

// ---- 1) 登录 ----
send($sock, MSG_LOGIN, $token);
$r = readFrame($sock, 3.0);
if ($r === null || $r[0] !== MSG_LOGIN_OK) {
    fwrite(STDERR, "login failed: " . var_export($r, true) . PHP_EOL);
    exit(1);
}
$uid = (int) $r[1];
echo "[" . $uid . "] login ok, mode=" . $mode . PHP_EOL;

$foe = 0;
$side = 0;

if ($rejoin) {
    // ---- 2a) 重连找回：不发匹配，直接问"我还在哪一局" ----
    send($sock, MSG_REJOIN);
    echo "[" . $uid . "] rejoining..." . PHP_EOL;
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $r = readFrame($sock, 0.5);
        if ($r === null) {
            continue;
        }
        if ($r[0] === MSG_NOTICE) {
            $n = json_decode($r[1], true);
            $code = is_array($n) && isset($n["code"]) ? (string) $n["code"] : "notice";
            echo "[" . $uid . "] NOTICE " . $code . PHP_EOL;
            exit(4);
        }
        if ($r[0] === MSG_REJOIN_OK) {
            $info = json_decode($r[1], true);
            $foe = (int) $info["foe"];
            $side = (int) $info["side"];
            echo "[" . $uid . "] REJOINED room " . $info["room"] . " vs " . $foe . " (side " . $side . ")" . PHP_EOL;
            break;
        }
    }
    if ($foe === 0) {
        echo "[" . $uid . "] rejoin timeout" . PHP_EOL;
        exit(4);
    }
} else {
    // ---- 2b) 入匹配 ----
    send($sock, MSG_MATCH_JOIN);
    echo "[" . $uid . "] matching..." . PHP_EOL;

    $deadline = microtime(true) + 15.0;
    while (microtime(true) < $deadline) {
        $r = readFrame($sock, 0.5);
        if ($r === null) {
            continue;
        }
        if ($r[0] === MSG_NOTICE) {
            $n = json_decode($r[1], true);
            $code = is_array($n) && isset($n["code"]) ? (string) $n["code"] : "notice";
            $text = is_array($n) && isset($n["text"]) ? (string) $n["text"] : "";
            echo "[" . $uid . "] NOTICE " . $code . ": " . $text . PHP_EOL;
            exit(3);
        }
        if ($r[0] === MSG_MATCH_FOUND) {
            $found = json_decode($r[1], true);
            $foe = (int) $found["foe"];
            $side = (int) $found["side"];
            echo "[" . $uid . "] MATCHED vs " . $foe . " (room " . $found["room"] . ", side " . $side . ")" . PHP_EOL;
            break;
        }
    }
}
if ($foe === 0) {
    echo "[" . $uid . "] match timeout" . PHP_EOL;
    exit(2);
}

// ---- 3) 对战 ----
$lastAttack = 0.0;
$lastDir = -99;
$lastPrint = 0.0;
$start = microtime(true);
$myX = 0;
$myHp = 100;
$foeX = 0;
$foeHp = 100;

while (microtime(true) - $start < 70.0) {
    $r = readFrame($sock, 0.1);
    $now = microtime(true);

    if ($r !== null) {
        if ($r[0] === MSG_STATE) {
            $st = json_decode($r[1], true);
            if ((int) $st["uidA"] === $uid) {
                $myHp = (int) $st["hpA"];
                $myX = (int) $st["xA"];
                $foeHp = (int) $st["hpB"];
                $foeX = (int) $st["xB"];
            } else {
                $myHp = (int) $st["hpB"];
                $myX = (int) $st["xB"];
                $foeHp = (int) $st["hpA"];
                $foeX = (int) $st["xA"];
            }
            if ($now - $lastPrint > 1.0) {
                $lastPrint = $now;
                echo "[" . $uid . "] hp=" . $myHp . " x=" . $myX . "  |  foe hp=" . $foeHp . " x=" . $foeX . PHP_EOL;
            }
        } elseif ($r[0] === MSG_RESULT) {
            $res = json_decode($r[1], true);
            $w = (int) $res["winner"];
            $label = $w === $uid ? "WIN" : ($w === -1 ? "DRAW" : "LOSE");
            echo "[" . $uid . "] RESULT " . $label . " (winner=" . $w . ")" . PHP_EOL;
            fclose($sock);
            exit($w === $uid ? 0 : 3);
        }
    }

    // ---- 决策 ----
    $dist = $myX > $foeX ? $myX - $foeX : $foeX - $myX;
    $dir = 0;

    if ($mode === "aggressive") {
        // 冲上去：离得远就往对方走，够得着就停
        if ($dist > ATTACK_RANGE - 200) {
            $dir = $foeX > $myX ? 1 : -1;
        }
    } else {
        // passive：原地不动，被贴近了才后退
        if ($dist < ATTACK_RANGE - 400) {
            $dir = $foeX > $myX ? -1 : 1;
        }
    }

    if ($dir !== $lastDir) {
        send($sock, MSG_MOVE, (string) $dir);
        $lastDir = $dir;
    }

    // 够得着就打；服务端还有 800ms 冷却，这里按 850ms 节流，少发无用包
    if ($dist <= ATTACK_RANGE && $now - $lastAttack > 0.85) {
        send($sock, MSG_ATTACK);
        $lastAttack = $now;
    }
}

echo "[" . $uid . "] 超时退出" . PHP_EOL;
fclose($sock);
exit(4);
