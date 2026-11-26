<?php

// TLS 守卫客户端（解释执行）。用法：php85 client.php <tcp|ws> <port>
//
//   tcp 模式：ssl:// 回显、tcp://（明文）应当拿不到回显
//   ws  模式：wss（ssl:// 上做 WebSocket 握手 + 一帧二进制回显）、明文 ws 握手应当失败
// 行尾打 OK / FAIL，run.sh 靠这个判定（同 gw-guard 的约定）。

function frame(int $id, string $body): string
{
    return pack("NnCC", 8 + strlen($body), $id, 0, 0) . $body;
}

function check(bool $ok, string $name, string $got): void
{
    echo $name . ": " . ($ok ? "OK" : "FAIL") . "  [" . $got . "]\n";
}

/** 在一条已经建好的流上写一帧、读一帧（按帧长解析） */
function talkStream($s, string $frame): string
{
    fwrite($s, $frame);
    $buf = "";
    $end = microtime(true) + 1.5;
    while (microtime(true) < $end) {
        $d = @fread($s, 4096);
        if (is_string($d) && $d !== "") {
            $buf .= $d;
        }
        if (strlen($buf) >= 8) {
            $len = unpack("N", substr($buf, 0, 4))[1];
            if (strlen($buf) >= $len) {
                return substr($buf, 8, $len - 8);
            }
        }
        usleep(5000);
    }
    return "";
}

function openSsl(int $port)
{
    $ctx = stream_context_create(["ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
        "allow_self_signed" => true,
    ]]);
    // 关掉 SNI 之类的花活：这里只验"TLS 握手能不能成、成完能不能用"
    return @stream_socket_client("ssl://127.0.0.1:" . $port, $errno, $errstr, 2.0, STREAM_CLIENT_CONNECT, $ctx);
}

function openPlain(int $port)
{
    return @stream_socket_client("tcp://127.0.0.1:" . $port, $errno, $errstr, 2.0);
}

/** 在流上做 WebSocket 握手；成功返回 true */
function wsHandshake($s, int $port): bool
{
    $key = base64_encode(random_bytes(16));
    $req = "GET / HTTP/1.1\r\nHost: 127.0.0.1:" . $port . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: " . $key . "\r\nSec-WebSocket-Version: 13\r\n\r\n";
    fwrite($s, $req);
    $buf = "";
    $end = microtime(true) + 1.5;
    while (microtime(true) < $end) {
        $d = @fread($s, 4096);
        if (!is_string($d)) {
            break;
        }
        $buf .= $d;
        if (strpos($buf, "\r\n\r\n") !== false) {
            break;
        }
        usleep(5000);
    }
    return strpos($buf, " 101 ") !== false;
}

/** 发一个带掩码的二进制帧，读回一个二进制帧的载荷 */
function wsEcho($s, string $payload): string
{
    $mask = "\x11\x22\x33\x44";
    $n = strlen($payload);
    $head = chr(0x82);
    if ($n < 126) {
        $head .= chr(0x80 | $n);
    } else {
        $head .= chr(0x80 | 126) . pack("n", $n);
    }
    $masked = "";
    for ($i = 0; $i < $n; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }
    fwrite($s, $head . $mask . $masked);
    // 读回：不带掩码的二进制帧
    $buf = "";
    $end = microtime(true) + 1.5;
    while (microtime(true) < $end) {
        $d = @fread($s, 4096);
        if (is_string($d) && $d !== "") {
            $buf .= $d;
        }
        if (strlen($buf) >= 4) {
            $opcode = ord($buf[0]) & 0x0F;
            $ln = ord($buf[1]) & 0x7F;
            $off = 2;
            if ($ln === 126) {
                $ln = unpack("n", substr($buf, 2, 2))[1];
                $off = 4;
            }
            if (strlen($buf) >= $off + $ln) {
                return $opcode === 2 ? substr($buf, $off, $ln) : "(opcode " . $opcode . ")";
            }
        }
        usleep(5000);
    }
    return "";
}

$mode = $argv[1] ?? "tcp";
$port = (int) ($argv[2] ?? "9420");

if ($mode === "tcp") {
    $s = openSsl($port);
    if ($s === false) {
        echo "TLS 连接: FAIL  [握手失败]\n";
    } else {
        $r = talkStream($s, frame(1001, "secret-payload"));
        fclose($s);
        check($r === "secret-payload", "TLS 之上能正常收发业务帧", $r === "" ? "(no reply)" : $r);
    }
    $p = openPlain($port);
    if ($p === false) {
        check(true, "明文连接拿不到回显", "连接被拒");
    } else {
        $r = talkStream($p, frame(1001, "plain-payload"));
        fclose($p);
        check($r === "", "明文连接拿不到回显（不会静默降级成明文）", $r === "" ? "no reply" : "got reply: " . $r);
    }
} else {
    $s = openSsl($port);
    if ($s === false) {
        echo "WSS 握手: FAIL  [TLS 握手失败]\n";
    } else {
        $ok = wsHandshake($s, $port);
        check($ok, "WSS：TLS 之上的 WebSocket 升级成功", $ok ? "101" : "no 101");
        $r = wsEcho($s, frame(1001, "wss-payload"));
        fclose($s);
        // 服务端回的是完整的 TSF 帧（8 字节头 + 载荷）：载荷在偏移 8 处
        check(substr($r, 8) === "wss-payload", "WSS：二进制帧回显", $r === "" ? "(no frame)" : "回包 " . strlen($r) . " 字节");
    }
    $p = openPlain($port);
    if ($p === false) {
        check(true, "明文 WebSocket 连接被拒", "连接被拒");
    } else {
        $ok = wsHandshake($p, $port);
        fclose($p);
        check(!$ok, "明文 WebSocket 升级失败（不会静默降级成明文）", $ok ? "101" : "rejected");
    }
}
