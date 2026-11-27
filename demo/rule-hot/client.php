<?php

// 最小客户端：登录，然后连续发 N 次 raid 请求，打印累计结果。
//
//   php85 client.php <port> <token> <次数> <标签>
//
// 输出一行 `label=... n=... items=... gold=...`，run.sh 直接抓这一行做前后对比。
//
// 只依赖协议的"契约 + 头实现"三个文件，不需要传输层 / Gateway。
require_once __DIR__ . "/../../tsf/src/Protocol/Contract/DecodedFrame.php";
require_once __DIR__ . "/../../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../../tsf/src/Protocol/Frame/HeaderFrame.php";

use TSF\Protocol\Frame\HeaderFrame;

$port = (int) ($argv[1] ?? 9603);
$token = (string) ($argv[2] ?? "tok-1001");
$times = (int) ($argv[3] ?? 200);
$label = (string) ($argv[4] ?? "run");

$sock = stream_socket_client("tcp://127.0.0.1:" . $port, $errno, $errstr, 3);
if ($sock === false) {
    fwrite(STDERR, "connect failed: " . $errstr . PHP_EOL);
    exit(1);
}

/**
 * 读一帧，返回 [msgId, body]；超时返回 null。
 * 缓冲区用 static 保留：一次读到两帧时第二帧不会丢。
 */
function readFrame($sock, float $timeout = 5.0): ?array
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
            usleep(500);
            continue;
        }
        $buf .= $chunk;
    }
    return null;
}

// 1) 登录
fwrite($sock, HeaderFrame::build(1, $token));
$r = readFrame($sock);
if ($r === null || $r[0] !== 2) {
    fwrite(STDERR, "login failed" . PHP_EOL);
    exit(1);
}
$uid = (int) $r[1];

// 2) 连续 raid
$lastItems = 0;
$lastGold = 0;
$fail = 0;
$t0 = microtime(true);

for ($i = 0; $i < $times; $i++) {
    fwrite($sock, HeaderFrame::build(1001, "1"));
    $resp = readFrame($sock);
    if ($resp === null || $resp[0] !== 1002) {
        $fail++;
        continue;
    }
    // body = "kills=1 drops=1 spec=3001:1 gold=120 items=37"
    $kv = [];
    foreach (explode(" ", $resp[1]) as $one) {
        $p = explode("=", $one, 2);
        if (count($p) === 2) {
            $kv[$p[0]] = $p[1];
        }
    }
    $lastItems = isset($kv["items"]) ? (int) $kv["items"] : $lastItems;
    $lastGold = isset($kv["gold"]) ? (int) $kv["gold"] : $lastGold;
}

$ms = (int) ((microtime(true) - $t0) * 1000);
fclose($sock);

// 这一行是给 run.sh 抓的，格式固定
echo "label=" . $label
    . " uid=" . $uid
    . " n=" . $times
    . " fail=" . $fail
    . " items=" . $lastItems
    . " gold=" . $lastGold
    . " ms=" . $ms
    . PHP_EOL;

exit($fail === 0 ? 0 : 1);
