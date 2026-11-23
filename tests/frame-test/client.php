<?php

// 拆包/粘包健壮性测试（从 demo/frame-test.php 归位到回归套件）
//   1) 一帧拆成两次发（模拟 TCP 分段）
//   2) 两帧合并一次发（模拟粘包）
//   3) 非法帧头应被断开
//   4) 异常后服务仍可用
//
// 注意：读回包必须"按帧长度精确读"，不能用"读一块再解一帧"——
// 后者在服务端足够快、两帧一起到达时会把第二帧丢掉（曾经误判过）。
require_once __DIR__ . "/../../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../../tsf/src/Protocol/Frame/HeaderFrame.php";

use TSF\Protocol\Frame\HeaderFrame;

$port = (int) ($argv[1] ?? 9001);

function conn(int $port)
{
    $s = stream_socket_client("tcp://127.0.0.1:" . $port, $errno, $errstr, 3);
    if ($s === false) {
        fwrite(STDERR, "connect failed: " . $errstr . PHP_EOL);
        exit(1);
    }
    stream_set_timeout($s, 3);
    return $s;
}

function readExact($sock, int $n): string
{
    $buf = "";
    while (strlen($buf) < $n) {
        $chunk = fread($sock, $n - strlen($buf));
        if ($chunk === false || $chunk === "") {
            return "";
        }
        $buf .= $chunk;
    }
    return $buf;
}

function readFrame($sock): ?array
{
    $hdr = readExact($sock, 8);
    if (strlen($hdr) < 8) {
        return null;
    }
    $len = HeaderFrame::u32($hdr, 0);
    if ($len < 8 || $len > 1024 * 1024) {
        return null;
    }
    $body = "";
    if ($len > 8) {
        $body = readExact($sock, $len - 8);
        if (strlen($body) !== $len - 8) {
            return null;
        }
    }
    return [HeaderFrame::u16($hdr, 4), ord($hdr[6]), $body];
}

/** 登录（默认配置 server.auth.require=true，业务消息必须登录后才能发） */
function login($sock): void
{
    fwrite($sock, HeaderFrame::build(1, "tok-1001"));
    $r = readFrame($sock);
    if ($r === null || $r[0] !== 2) {
        fwrite(STDERR, "login failed: " . var_export($r, true) . PHP_EOL);
        exit(1);
    }
}

// --- 用例 1：分段发送 ---
$s = conn($port);
login($s);
$frame = HeaderFrame::build(1001, "split-frame-test");
fwrite($s, substr($frame, 0, 5));
usleep(20000);
fwrite($s, substr($frame, 5));
$r = readFrame($s);
echo "case1 分段发送: " . ($r !== null && $r[2] === "split-frame-test" ? "OK" : "FAIL") . PHP_EOL;
fclose($s);

// --- 用例 2：粘包（两帧一次写）---
$s = conn($port);
login($s);
fwrite($s, HeaderFrame::build(1001, "sticky-A") . HeaderFrame::build(1001, "sticky-B"));
$a = readFrame($s);
$b = readFrame($s);
$ok2 = ($a !== null && $b !== null && $a[2] === "sticky-A" && $b[2] === "sticky-B");
echo "case2 粘包: " . ($ok2 ? "OK" : "FAIL") . PHP_EOL;
fclose($s);

// --- 用例 3：非法帧头 ---
$s = conn($port);
fwrite($s, "\x00\x00\x00\x01" . str_repeat("x", 8));
usleep(50000);
$after = @fread($s, 64);
$closed = ($after === "" || $after === false);
echo "case3 非法帧被断开: " . ($closed ? "OK" : "FAIL") . PHP_EOL;
fclose($s);

// --- 用例 4：异常后仍可用 ---
$s = conn($port);
login($s);
fwrite($s, HeaderFrame::build(1001, "after-bad-frame"));
$r = readFrame($s);
echo "case4 异常后仍可用: " . ($r !== null && $r[2] === "after-bad-frame" ? "OK" : "FAIL") . PHP_EOL;
fclose($s);
