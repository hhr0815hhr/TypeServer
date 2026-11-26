<?php

// 网关守卫客户端（解释执行，不受 AOT 约束）。
//
// 观察的是"handler 抛异常之后"：
//   本连接、同一次收包里排在后面的帧、其它连接、进程，各自怎样。
// 行尾打 OK / FAIL，run.sh 靠这个判定（同 frame-test 的约定）。

function frame(int $id, string $body): string
{
    return pack("NnCC", 8 + strlen($body), $id, 0, 0) . $body;
}

function parseFrames(string $buf): array
{
    $out = [];
    while (strlen($buf) >= 8) {
        $len = unpack("N", substr($buf, 0, 4))[1];
        if (strlen($buf) < $len) {
            break;
        }
        $id = unpack("n", substr($buf, 4, 2))[1];
        $out[] = [$id, substr($buf, 8, $len - 8)];
        $buf = substr($buf, $len);
    }
    return $out;
}

function drain($s, float $sec): array
{
    stream_set_blocking($s, false);
    $buf = "";
    $end = microtime(true) + $sec;
    while (microtime(true) < $end) {
        $d = fread($s, 262144);
        if (is_string($d) && $d !== "") {
            $buf .= $d;
        }
        usleep(5000);
    }
    stream_set_blocking($s, true);
    return parseFrames($buf);
}

function fmt(array $frames): string
{
    $parts = [];
    foreach ($frames as $f) {
        $parts[] = $f[0] . ":" . $f[1];
    }
    return $parts === [] ? "(none)" : implode(" ", $parts);
}

function check(bool $ok, string $name, string $got): void
{
    echo $name . ": " . ($ok ? "OK" : "FAIL") . "  [" . $got . "]\n";
}

$port = (int) ($argv[1] ?? "9411");
$addr = "tcp://127.0.0.1:" . $port;
$a = stream_socket_client($addr, $errno, $errstr, 2);
$b = stream_socket_client($addr, $errno2, $errstr2, 2);
if ($a === false || $b === false) {
    echo "连接服务端: FAIL  [connect failed]\n";
    exit(1);
}

// 登录：token → uid
fwrite($a, frame(1, "tok-1"));
$la = fmt(drain($a, 0.4));
fwrite($b, frame(1, "tok-2"));
$lb = fmt(drain($b, 0.4));
check(strpos($la, "2:1") !== false && strpos($lb, "2:2") !== false, "两条连接登录", $la . " | " . $lb);

fwrite($a, frame(1001, "warm"));
$r = fmt(drain($a, 0.3));
check($r === "1002:warm", "基线：echo 正常", $r);

// 单帧抛异常：必须回 ERR，连接不断
fwrite($a, frame(2001, "x"));
$r = fmt(drain($a, 0.6));
check($r === "400:handler error", "handler 抛异常 → 回一帧 ERR", $r);
check(!feof($a), "handler 抛异常 → 连接不断", feof($a) ? "eof" : "open");

fwrite($a, frame(1001, "after"));
$r = fmt(drain($a, 0.5));
check($r === "1002:after", "抛异常之后本连接继续可用", $r);

fwrite($b, frame(1001, "b1"));
$r = fmt(drain($b, 0.5));
check($r === "1002:b1", "抛异常不影响其它连接", $r);

// 一次 write 三帧，抛异常的那帧在最前面：后面两帧不能被它带走。
// 这是修之前最主要的症状——p2/p3 静默丢失，客户端只能等到超时。
fwrite($a, frame(2001, "p1") . frame(1001, "p2") . frame(1001, "p3"));
$r = fmt(drain($a, 0.6));
check($r === "400:handler error 1002:p2 1002:p3", "同一次收包：异常帧之后的帧照常处理", $r);

fwrite($a, frame(1001, "p4"));
$r = fmt(drain($a, 0.6));
check($r === "1002:p4", "流水之后本连接仍可用", $r);

fwrite($b, frame(1001, "b2"));
$r = fmt(drain($b, 0.5));
check($r === "1002:b2", "流水之后其它连接仍可用", $r);

// 再抛两次：确认不是"只兜住第一次"
fwrite($a, frame(2001, "q1") . frame(2001, "q2") . frame(1001, "q3"));
$r = fmt(drain($a, 0.6));
check($r === "400:handler error 400:handler error 1002:q3", "连续两个异常帧各自回 ERR", $r);

fclose($a);
fclose($b);
