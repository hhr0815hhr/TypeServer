<?php

// 积压续处理客户端（解释执行）：一次 write 灌 N 帧，数回包。
//
// 修之前（2026-11-25 实测）：一次发 3000 帧只回 2002，之后再发 1 帧才多回 999——
// 剩下的帧留在缓冲里，要等这条连接下一次发来任何字节才会被处理。
// 客户端"发一批、然后只等回包"是再正常不过的用法，后面的回包却永远不来。

function frame(int $id, string $body): string
{
    return pack("NnCC", 8 + strlen($body), $id, 0, 0) . $body;
}

// 跨次 fread 的半帧不能丢：维护一个缓冲，按帧长解析后再计数
function countReplies($s, float $sec, string &$carry): int
{
    stream_set_blocking($s, false);
    $got = 0;
    $end = microtime(true) + $sec;
    $buf = $carry;
    $carry = "";
    while (microtime(true) < $end) {
        $d = fread($s, 262144);
        if (is_string($d) && $d !== "") {
            $buf .= $d;
        }
        while (strlen($buf) >= 8) {
            $len = unpack("N", substr($buf, 0, 4))[1];
            if (strlen($buf) < $len) {
                break;
            }
            $got++;
            $buf = substr($buf, $len);
        }
        // 已经够了就别干等满 2 秒
        if ($got >= $GLOBALS["want"]) {
            break;
        }
        usleep(5000);
    }
    $carry = $buf;
    stream_set_blocking($s, true);
    return $got;
}

function check(bool $ok, string $name, string $got): void
{
    echo $name . ": " . ($ok ? "OK" : "FAIL") . "  [" . $got . "]\n";
}

$n = (int) ($argv[1] ?? "3000");
$addr = "tcp://127.0.0.1:9414";
$a = stream_socket_client($addr, $errno, $errstr, 2);
if ($a === false) {
    echo "连接服务端: FAIL  [connect failed]\n";
    exit(1);
}

$ca = "";
$GLOBALS["want"] = 1;
fwrite($a, frame(1001, "warm"));
$r = countReplies($a, 0.5, $ca);
check($r === 1, "基线：echo 正常", "replies=$r");

// 一次 write 灌 N 帧，然后**什么都不发**，只等。
$blob = "";
for ($i = 0; $i < $n; $i++) {
    $blob .= frame(1001, "aaaa");
}
$GLOBALS["want"] = $n;
$t0 = microtime(true);
fwrite($a, $blob);
$r1 = countReplies($a, 2.0, $ca);
$ms = (microtime(true) - $t0) * 1000;
check($r1 === $n, "一次 write $n 帧：不催也全部回包（" . round($ms) . "ms 内）", "replies=$r1/$n");

// 再来一批：确认续处理不是"只管第一次"
$t0 = microtime(true);
fwrite($a, $blob);
$r2 = countReplies($a, 2.0, $ca);
$ms = (microtime(true) - $t0) * 1000;
check($r2 === $n, "再来一批 $n 帧：同样全部回包（" . round($ms) . "ms 内）", "replies=$r2/$n");

// 公平性：A 的积压在消化时，另一条连接的请求要能插进来。
// 续处理是"每 1000 帧让位一次"，让位必须真的让出去，而不是变成独占。
$b = stream_socket_client($addr, $errno, $errstr, 2);
if ($b === false) {
    echo "连接第二条: FAIL  [connect failed]\n";
    exit(1);
}
$cb = "";
$GLOBALS["want"] = 1;
fwrite($a, $blob);
$t0 = microtime(true);
fwrite($b, frame(1001, "bbbb"));
$rb = countReplies($b, 2.0, $cb);
$ms = (microtime(true) - $t0) * 1000;
check($rb === 1 && $ms < 500.0, "A 积压消化期间 B 的请求及时应答", "replies=$rb in " . round($ms, 1) . "ms");

fclose($a);
fclose($b);
