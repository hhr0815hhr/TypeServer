<?php

/**
 * 三开客户端：验证"建房 → 列表 → 加入 → 开局 → 出牌"整条链路。
 *
 *   php85 client.php [port]
 *
 * 三个连接分别用 tok-1001/1002/1003 登录，A 建房、B 和 C 加入，
 * 三人齐了服务端自动开局，然后 A 出一张最小的单牌。
 *
 * 不做完整对局——规则正确性由 tests/unit 覆盖（53 条断言，秒级跑完）。
 * 这里只证明"网络到规则的那一段"是通的。
 */

require_once __DIR__ . "/../../tsf/src/Protocol/Contract/DecodedFrame.php";
require_once __DIR__ . "/../../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../../tsf/src/Protocol/Frame/HeaderFrame.php";

use TSF\Protocol\Frame\HeaderFrame;

$port = (int) ($argv[1] ?? 9061);

const MSG_LOGIN = 1;
const MSG_LOGIN_OK = 2;
const CMD_LIST_REQ = 1001;
const CMD_LIST_RESP = 1002;
const CMD_CREATE_REQ = 1003;
const CMD_CREATE_RESP = 1004;
const CMD_JOIN_REQ = 1005;
const CMD_JOIN_RESP = 1006;
const CMD_ERR_RESP = 1008;
const CMD_PLAY_REQ = 1009;
const CMD_PLAY_RESP = 1010;
const PUSH_DEAL = 1021;

/**
 * 连上并登录，返回 [sock, uid, buf]。
 *
 * buf 要跟着连接走：三个连接共用一份缓冲的话，A 的数据会被 B 读到，
 * 现象是 "frame: too large"（把对方的 JSON 当成帧头解析了）。
 *
 * @return array{0: resource, 1: int, 2: string}
 */
function login(string $token, int $port): array
{
    $sock = stream_socket_client("tcp://127.0.0.1:" . $port, $errno, $errstr, 3);
    if ($sock === false) {
        fwrite(STDERR, "connect failed: " . $errstr . PHP_EOL);
        exit(1);
    }
    $buf = "";
    fwrite($sock, HeaderFrame::build(MSG_LOGIN, $token));
    $r = readFrame($sock, 3.0, $buf);
    if ($r === null || $r[0] !== MSG_LOGIN_OK) {
        fwrite(STDERR, "login failed for " . $token . PHP_EOL);
        exit(1);
    }
    echo "  登录 " . $token . " → uid=" . $r[1] . PHP_EOL;
    return [$sock, (int) $r[1], $buf];
}

/**
 * 读一帧。$buf 是这个连接**自己的**接收缓冲，跨调用保留。
 *
 * @return array{0: int, 1: string}|null
 */
function readFrame($sock, float $timeout, string &$buf): ?array
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $f = HeaderFrame::parse($buf);
        if ($f !== null) {
            $buf = substr($buf, $f->consumed);
            return [$f->msgId, $f->body];
        }
        $chunk = fread($sock, 8192);
        if ($chunk === false || $chunk === "") {
            if (feof($sock)) {
                return null;
            }
            usleep(1000);
            continue;
        }
        $buf .= $chunk;
    }
    return null;
}

/** 一直读到指定 msgId（跳过中间的推送），超时返回 null */
function waitFor($sock, int $msgId, string &$buf, float $timeout = 3.0): ?string
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $r = readFrame($sock, $deadline - microtime(true), $buf);
        if ($r === null) {
            return null;
        }
        if ($r[0] === $msgId) {
            return $r[1];
        }
        if ($r[0] === CMD_ERR_RESP) {
            echo "  服务端错误：" . $r[1] . PHP_EOL;
            return null;
        }
    }
    return null;
}

echo "== 斗地主大厅（端口 " . $port . "）==" . PHP_EOL;

echo PHP_EOL . "[1] 三个玩家登录" . PHP_EOL;
[$a, $ua, $bufA] = login("tok-a", $port);
[$b, $ub, $bufB] = login("tok-b", $port);
[$c, $uc, $bufC] = login("tok-c", $port);

echo PHP_EOL . "[2] A 建房（房卡档位 1，消耗 1 张）" . PHP_EOL;
fwrite($a, HeaderFrame::build(CMD_CREATE_REQ, '{"level":1}'));
$body = waitFor($a, CMD_CREATE_RESP, $bufA);
if ($body === null) {
    echo "  FAIL 没收到建房应答" . PHP_EOL;
    exit(1);
}
$info = json_decode($body, true);
$roomId = (int) ($info["roomId"] ?? 0);
echo "  建房成功 roomId=" . $roomId . "，剩余房卡 " . ($info["cards"] ?? "?") . PHP_EOL;

echo PHP_EOL . "[3] 房间列表" . PHP_EOL;
fwrite($a, HeaderFrame::build(CMD_LIST_REQ, ""));
$body = waitFor($a, CMD_LIST_RESP, $bufA);
if ($body === null) {
    echo "  FAIL 没收到列表" . PHP_EOL;
    exit(1);
}
$list = json_decode($body, true);
$rooms = $list["rooms"] ?? [];
echo "  列表有 " . count($rooms) . " 个房间" . PHP_EOL;
foreach ($rooms as $r) {
    echo "    #" . $r["roomId"] . " 档位 " . $r["level"] . " 人数 " . $r["seated"] . PHP_EOL;
}

echo PHP_EOL . "[4] B 和 C 加入" . PHP_EOL;
fwrite($b, HeaderFrame::build(CMD_JOIN_REQ, '{"roomId":' . $roomId . '}'));
if (waitFor($b, CMD_JOIN_RESP, $bufB) === null) {
    echo "  FAIL B 加入失败" . PHP_EOL;
    exit(1);
}
echo "  B 加入成功" . PHP_EOL;

fwrite($c, HeaderFrame::build(CMD_JOIN_REQ, '{"roomId":' . $roomId . '}'));
if (waitFor($c, CMD_JOIN_RESP, $bufC) === null) {
    echo "  FAIL C 加入失败" . PHP_EOL;
    exit(1);
}
echo "  C 加入成功（三人齐，服务端自动开局）" . PHP_EOL;

echo PHP_EOL . "[5] 三家收手牌" . PHP_EOL;
$handA = null;
$dealA = waitFor($a, PUSH_DEAL, $bufA);
if ($dealA !== null) {
    $d = json_decode($dealA, true);
    $handA = $d["hand"] ?? [];
    echo "  A 收到 " . count($handA) . " 张（地主），地主座位 " . ($d["landlord"] ?? "?") . PHP_EOL;
} else {
    echo "  FAIL A 没收到手牌" . PHP_EOL;
    exit(1);
}
$dealB = waitFor($b, PUSH_DEAL, $bufB);
$dB = $dealB === null ? [] : (json_decode($dealB, true)["hand"] ?? []);
echo "  B 收到 " . count($dB) . " 张" . PHP_EOL;
$dealC = waitFor($c, PUSH_DEAL, $bufC);
$dC = $dealC === null ? [] : (json_decode($dealC, true)["hand"] ?? []);
echo "  C 收到 " . count($dC) . " 张" . PHP_EOL;

if (count($handA) !== 20 || count($dB) !== 17 || count($dC) !== 17) {
    echo "  FAIL 手牌数不对（地主应 20、农民各 17）" . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "[6] A 出一张最小单牌" . PHP_EOL;
$low = $handA[0];
fwrite($a, HeaderFrame::build(CMD_PLAY_REQ, '{"cards":[' . $low . ']}'));
$resp = waitFor($a, CMD_PLAY_RESP, $bufA);
if ($resp === null) {
    echo "  FAIL 出牌没有应答" . PHP_EOL;
    exit(1);
}
echo "  出牌成功：" . $resp . PHP_EOL;

echo PHP_EOL . "OK: 大厅 / 房卡 / 房间列表 / 开局 / 出牌 全链路通过" . PHP_EOL;
foreach ([$a, $b, $c] as $s) {
    fclose($s);
}
