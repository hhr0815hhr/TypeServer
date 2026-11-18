<?php

// 最小客户端：用框架同一份帧实现编解码，验证端到端。
// 客户端只需要协议的"契约 + 头实现"三个文件（不需要传输层/Gateway）。
require_once __DIR__ . "/../../tsf/src/Protocol/Contract/DecodedFrame.php";
require_once __DIR__ . "/../../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../../tsf/src/Protocol/Frame/HeaderFrame.php";

use TSF\Protocol\Frame\HeaderFrame;

$port = (int) ($argv[1] ?? 9001);
$token = (string) ($argv[2] ?? "tok-1001");

$sock = stream_socket_client("tcp://127.0.0.1:" . $port, $errno, $errstr, 3);
if ($sock === false) {
    fwrite(STDERR, "connect failed: " . $errstr . PHP_EOL);
    exit(1);
}

/**
 * 读一帧，返回 [msgId, body]；超时返回 null。
 * 缓冲区用 static 保留：一次读到两帧时，第二帧不会丢。
 */
function readFrame($sock, float $timeout = 3.0): ?array
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

// 1) 登录（默认配置要求鉴权，业务消息必须登录后才能发）
fwrite($sock, HeaderFrame::build(1, $token));          // 1 = MSG_LOGIN
$r = readFrame($sock);
if ($r === null || $r[0] !== 2) {                       // 2 = MSG_LOGIN_OK
    fwrite(STDERR, "login failed: " . var_export($r, true) . PHP_EOL);
    exit(1);
}
echo "login ok uid=" . $r[1] . PHP_EOL;

// 2) echo 往返
$payload = "hello-tsf-" . time();
fwrite($sock, HeaderFrame::build(1001, $payload));      // 1001 = MSG_ECHO_REQ
$r = readFrame($sock);
if ($r === null) {
    fwrite(STDERR, "timeout waiting response" . PHP_EOL);
    fclose($sock);
    exit(1);
}
echo "resp msgId=" . $r[0] . " body=" . $r[1] . PHP_EOL;
echo ($r[1] === $payload ? "OK: echo matched" : "FAIL: body mismatch") . PHP_EOL;
fclose($sock);
exit($r[1] === $payload ? 0 : 1);
