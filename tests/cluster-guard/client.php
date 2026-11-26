<?php

// 集群守卫客户端（解释执行）：扮演一个"握得了手、但发坏包"的集群对端。
//
// $argv[1] = json  → 帧头合法、载荷不是合法 JSON（codec->unpack 抛异常）
// $argv[1] = len   → 长度字段非法（Packet::tryDecode 抛异常）
//
// 坏包之后再持续发合法包：修之前这些包全部堆在一条已经解不出东西的链路上，
// 对端看 hasPeer() 仍是 true、send() 仍返回 true——一条黑洞。

function frame(int $id, string $body): string
{
    return pack("NnCC", 8 + strlen($body), $id, 0, 0) . $body;
}

function echoOnce(string $tag): array
{
    $s = @stream_socket_client("tcp://127.0.0.1:9412", $errno, $errstr, 1.0);
    if ($s === false) {
        return [false, "connect-fail($errstr)"];
    }
    stream_set_timeout($s, 0, 400000);
    $t0 = microtime(true);
    fwrite($s, frame(1001, $tag));
    $r = fread($s, 8192);
    fclose($s);
    $ms = (microtime(true) - $t0) * 1000;
    if (!is_string($r) || strlen($r) < 8) {
        return [false, "no-reply"];
    }
    $len = unpack("N", substr($r, 0, 4))[1];
    $body = substr($r, 8, $len - 8);
    return [$body === $tag, $body . " rtt=" . round($ms, 1) . "ms"];
}

function packet(int $dst, int $src, int $session, int $msgId, string $payload): string
{
    // | len(4, 整包) | dst(4) | src(4) | session(4) | msgId(2) | payload
    return pack("N", 18 + strlen($payload)) . pack("N", $dst) . pack("N", $src)
        . pack("N", $session) . pack("n", $msgId) . $payload;
}

function check(bool $ok, string $name, string $got): void
{
    echo $name . ": " . ($ok ? "OK" : "FAIL") . "  [" . $got . "]\n";
}

$mode = $argv[1] ?? "json";

list($ok, $got) = echoOnce("pre");
check($ok, "基线：TCP 入口 echo 正常", $got);

$c = @stream_socket_client("tcp://127.0.0.1:9413", $errno, $errstr, 2.0);
if ($c === false) {
    echo "连接集群端口: FAIL  [connect failed]\n";
    exit(1);
}
$body = "7\x1fjson\x1fdeadbeef0001\x1f-";
fwrite($c, "TSFC\x01" . packet(0, 7, 0, 0, $body));
stream_set_blocking($c, false);
$hello = "";
$end = microtime(true) + 0.6;
while (strlen($hello) < 5 && microtime(true) < $end) {
    $d = fread($c, 64);
    if (is_string($d) && $d !== "") {
        $hello .= $d;
    }
    usleep(5000);
}
check(substr($hello, 0, 5) === "TSFC\x01", "握手：收到对端 hello", strlen($hello) . " bytes, magic=" . substr($hello, 0, 5));

// 坏包
if ($mode === "len") {
    fwrite($c, str_repeat("\x00", 18));
    $what = "非法长度字段";
} else {
    fwrite($c, packet((1 << 24) | 99999, (7 << 24) | 1, 2, 50002, "{not json"));
    $what = "非法 JSON 载荷";
}

// 坏包之后持续发 1000 个合法包（约 300µs 一个）。服务端一旦丢弃链路，写会失败——那是预期的。
$sent = 0;
for ($i = 1; $i <= 1000; $i++) {
    if (@fwrite($c, packet((1 << 24) | 99999, (7 << 24) | 1, 1000 + $i, 50003, "{\"i\":" . $i . "}")) === false) {
        break;
    }
    $sent++;
    usleep(300);
}
// 读一下：对端关了连接的话 feof 会置位
fread($c, 4096);
$eof = feof($c);
check($eof, "坏包（" . $what . "）之后：链路被服务端丢弃（对端读到 EOF）", "eof=" . ($eof ? "yes" : "no") . " sent=" . $sent);

// 节点是否还在转：TCP 入口连续探测，每次都要回且不能变慢
$bad = 0;
$maxRtt = 0.0;
for ($i = 1; $i <= 5; $i++) {
    list($ok, $r) = echoOnce("post" . $i);
    if (!$ok) {
        $bad++;
    }
    if (preg_match('/rtt=([0-9.]+)ms/', $r, $m)) {
        $maxRtt = max($maxRtt, (float) $m[1]);
    }
    usleep(200000);
}
check($bad === 0, "坏包之后：节点继续处理请求（5 次 echo 探测）", "failed=" . $bad);
check($maxRtt < 200.0, "坏包之后：探测没有变慢", "max rtt=" . round($maxRtt, 1) . "ms");
fclose($c);
