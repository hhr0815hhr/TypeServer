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
const CMD_READY_REQ = 1013;
const CMD_READY_RESP = 1014;
const CMD_START_REQ = 1015;
const CMD_BID_REQ = 1017;
const CMD_BID_RESP = 1018;
const PUSH_DEAL = 1101;
const PUSH_BID = 1104;
const PUSH_BID_RESULT = 1105;

/**
 * WebSocket 握手。服务端 transport=ws，浏览器只能连 WS，所以这个验证客户端
 * 也走 WS——协议帧（8 字节头 + body）作为 WS 的 binary payload 传输。
 */
function wsHandshake($sock, string $host, int $port): bool
{
    $key = base64_encode(random_bytes(16));
    $req = "GET / HTTP/1.1\r\n"
        . "Host: " . $host . ":" . $port . "\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: " . $key . "\r\n"
        . "Sec-WebSocket-Version: 13\r\n\r\n";
    fwrite($sock, $req);
    $resp = "";
    while (strpos($resp, "\r\n\r\n") === false) {
        $c = fread($sock, 1024);
        if ($c === false || $c === "") {
            fwrite(STDERR, "握手无响应，已读到: " . var_export($resp, true) . PHP_EOL);
            return false;
        }
        $resp .= $c;
    }
    if (strpos($resp, " 101 ") === false) {
        fwrite(STDERR, "握手响应非 101: " . str_replace("\r\n", " | ", $resp) . PHP_EOL);
        return false;
    }
    return true;
}

/** 把一段数据包成客户端 binary 帧（客户端发出的帧必须 mask） */
function wsSend($sock, string $payload): void
{
    $len = strlen($payload);
    $frame = chr(0x82);   // FIN + opcode=2(binary)
    $mask = random_bytes(4);
    if ($len < 126) {
        $frame .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $frame .= chr(0x80 | 126) . pack("n", $len);
    } else {
        $frame .= chr(0x80 | 127) . pack("J", $len);
    }
    $frame .= $mask;
    for ($i = 0; $i < $len; $i++) {
        $frame .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
    }
    fwrite($sock, $frame);
}

/**
 * 从缓冲区里取一个 WS 帧的 payload。不够一帧返回 null。
 *
 * 只处理"服务端发的、不 mask 的、不分片"的帧——服务端确实这么发。
 */
function wsTake(string &$buf): ?string
{
    $n = strlen($buf);
    if ($n < 2) {
        return null;
    }
    $b0 = ord($buf[0]);
    $b1 = ord($buf[1]);
    $opcode = $b0 & 0x0F;
    $masked = ($b1 & 0x80) !== 0;
    $len = $b1 & 0x7F;
    $off = 2;

    if ($len === 126) {
        if ($n < 4) {
            return null;
        }
        $len = (ord($buf[2]) << 8) | ord($buf[3]);
        $off = 4;
    } elseif ($len === 127) {
        if ($n < 10) {
            return null;
        }
        $len = 0;
        for ($i = 2; $i < 10; $i++) {
            $len = ($len << 8) | ord($buf[$i]);
        }
        $off = 10;
    }
    $maskKey = "";
    if ($masked) {
        if ($n < $off + 4) {
            return null;
        }
        $maskKey = substr($buf, $off, 4);
        $off += 4;
    }
    if ($n < $off + $len) {
        return null;
    }
    $payload = substr($buf, $off, $len);
    if ($masked) {
        $un = "";
        for ($i = 0; $i < $len; $i++) {
            $un .= chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
        }
        $payload = $un;
    }
    $buf = substr($buf, $off + $len);

    // 关闭帧 / ping 直接吞掉（示例不处理 pong）
    if ($opcode === 0x8 || $opcode === 0x9) {
        return wsTake($buf);
    }
    return $payload;
}

/**
 * 连上（含 WS 握手）并登录，返回 [sock, uid, buf]。
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
    if (!wsHandshake($sock, "127.0.0.1", $port)) {
        fwrite(STDERR, "websocket handshake failed" . PHP_EOL);
        exit(1);
    }
    $buf = "";
    wsSend($sock, HeaderFrame::build(MSG_LOGIN, $token));
    $r = readFrame($sock, 3.0, $buf);
    if ($r === null || $r[0] !== MSG_LOGIN_OK) {
        fwrite(STDERR, "login failed for " . $token . PHP_EOL);
        exit(1);
    }
    echo "  登录 " . $token . " → uid=" . $r[1] . PHP_EOL;
    return [$sock, (int) $r[1], $buf];
}

/**
 * 读一帧。
 *
 * 这里是**两层缓冲**：$buf 存 WS 帧的原始字节，另一层（按 socket id 隔离）
 * 存拆出来的 TSF 帧字节。混成一份会出现"WS 帧头被当成 TSF 头解析"，
 * 现象还是那个 "frame: too large"。
 *
 * $buf 由调用方持有（每个连接一份），TSF 层用 socket id 在函数内隔离，
 * 这样调用点不用多传一个参数。
 *
 * @return array{0: int, 1: string}|null
 */
function readFrame($sock, float $timeout, string &$buf): ?array
{
    /** @var array<int, string> $tsf 按 socket id 隔离的 TSF 层缓冲 */
    static $tsf = [];

    $sid = get_resource_id($sock);
    if (!isset($tsf[$sid])) {
        $tsf[$sid] = "";
    }

    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        // 把缓冲区里所有完整的 WS 帧拆出来，payload 追加到 TSF 层
        while ($buf !== "") {
            $payload = wsTake($buf);
            if ($payload === null) {
                break;
            }
            $tsf[$sid] .= $payload;
        }
        $f = HeaderFrame::parse($tsf[$sid]);
        if ($f !== null) {
            $tsf[$sid] = substr($tsf[$sid], $f->consumed);
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
wsSend($a, HeaderFrame::build(CMD_CREATE_REQ, '{"level":1}'));
$body = waitFor($a, CMD_CREATE_RESP, $bufA);
if ($body === null) {
    echo "  FAIL 没收到建房应答" . PHP_EOL;
    exit(1);
}
$info = json_decode($body, true);
$roomId = (int) ($info["roomId"] ?? 0);
echo "  建房成功 roomId=" . $roomId . "，剩余房卡 " . ($info["cards"] ?? "?") . PHP_EOL;

echo PHP_EOL . "[3] 房间列表" . PHP_EOL;
wsSend($a, HeaderFrame::build(CMD_LIST_REQ, ""));
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
wsSend($b, HeaderFrame::build(CMD_JOIN_REQ, '{"roomId":' . $roomId . '}'));
if (waitFor($b, CMD_JOIN_RESP, $bufB) === null) {
    echo "  FAIL B 加入失败" . PHP_EOL;
    exit(1);
}
echo "  B 加入成功" . PHP_EOL;

wsSend($c, HeaderFrame::build(CMD_JOIN_REQ, '{"roomId":' . $roomId . '}'));
if (waitFor($c, CMD_JOIN_RESP, $bufC) === null) {
    echo "  FAIL C 加入失败" . PHP_EOL;
    exit(1);
}
echo "  C 加入成功（三人齐）" . PHP_EOL;

echo PHP_EOL . "[5] 三家准备，房主开始" . PHP_EOL;
wsSend($a, HeaderFrame::build(CMD_READY_REQ, ""));
waitFor($a, CMD_READY_RESP, $bufA);
wsSend($b, HeaderFrame::build(CMD_READY_REQ, ""));
waitFor($b, CMD_READY_RESP, $bufB);
wsSend($c, HeaderFrame::build(CMD_READY_REQ, ""));
waitFor($c, CMD_READY_RESP, $bufC);
echo "  三家都准备了" . PHP_EOL;

wsSend($a, HeaderFrame::build(CMD_START_REQ, ""));
$dealA = waitFor($a, PUSH_DEAL, $bufA, 5.0);
if ($dealA === null) {
    echo "  FAIL 开局没收到手牌" . PHP_EOL;
    exit(1);
}
$d = json_decode($dealA, true);
$handA = $d["hand"] ?? [];
echo "  开局收到 " . count($handA) . " 张" . PHP_EOL;

echo PHP_EOL . "[6] 叫分：A 叫 3 分" . PHP_EOL;
// 先等叫分轮次推送（可能已经先到）
$bid = waitFor($a, PUSH_BID, $bufA);
if ($bid !== null) {
    $bd = json_decode($bid, true);
    echo "  轮到座位 " . ($bd["seat"] ?? "?") . " 叫分" . PHP_EOL;
}
wsSend($a, HeaderFrame::build(CMD_BID_REQ, '{"score":3}'));
$res = waitFor($a, PUSH_BID_RESULT, $bufA, 5.0);
if ($res === null) {
    echo "  FAIL 没收到叫分结果" . PHP_EOL;
    exit(1);
}
$rd = json_decode($res, true);
echo "  地主座位 " . ($rd["landlord"] ?? "?") . "，叫分 " . ($rd["bid"] ?? "?") . PHP_EOL;

// 地主拿底牌后会再收到一次手牌
$dealA2 = waitFor($a, PUSH_DEAL, $bufA, 3.0);
if ($dealA2 !== null) {
    $d2 = json_decode($dealA2, true);
    $handA = $d2["hand"] ?? $handA;
    echo "  地主补底牌后 " . count($handA) . " 张" . PHP_EOL;
}
if (count($handA) !== 20) {
    echo "  FAIL 地主应有 20 张，实际 " . count($handA) . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "[7] A 出一张最小单牌" . PHP_EOL;
$low = $handA[0];
wsSend($a, HeaderFrame::build(CMD_PLAY_REQ, '{"cards":[' . $low . ']}'));
$resp = waitFor($a, CMD_PLAY_RESP, $bufA);
if ($resp === null) {
    echo "  FAIL 出牌没有应答" . PHP_EOL;
    exit(1);
}
echo "  出牌成功：" . $resp . PHP_EOL;

echo PHP_EOL . "OK: 登录 / 房卡 / 列表 / 加入 / 准备 / 开始 / 叫分 / 发牌 / 出牌 全链路通过" . PHP_EOL;
foreach ([$a, $b, $c] as $s) {
    fclose($s);
}
