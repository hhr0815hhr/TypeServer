<?php

/**
 * 协议切换测试：raw vs json。
 *
 * 验证"换 payload 不改代码"——同一份服务端二进制，只改配置。
 * 系统消息（登录/心跳）始终是原样字符串，不受 payload 影响。
 *
 * 用法：php85 proto-test.php <port> <mode:raw|json>
 */

require_once __DIR__ . "/../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../tsf/src/Protocol/Frame/HeaderFrame.php";
require_once __DIR__ . "/../tsf/src/Protocol/Msg.php";

use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Msg;

$port = (int) ($argv[1] ?? 9001);
$mode = $argv[2] ?? "raw";
$pass = 0;
$fail = 0;

function okk(string $name, bool $cond, string $detail = ""): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  " . $name . ($detail !== "" ? "  (" . $detail . ")" : "") . PHP_EOL;
    } else {
        $fail++;
        echo "  FAIL  " . $name . ($detail !== "" ? "  (" . $detail . ")" : "") . PHP_EOL;
    }
}

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

function send($s, int $msgId, string $body): void
{
    @fwrite($s, HeaderFrame::build($msgId, $body));
}

function readExact($s, int $n): string
{
    $buf = "";
    while (strlen($buf) < $n) {
        $c = @fread($s, $n - strlen($buf));
        if ($c === false || $c === "") {
            return "";
        }
        $buf .= $c;
    }
    return $buf;
}

/** @return array<int,mixed>|null [msgId, flags, body] */
function recvFrame($s): ?array
{
    $hdr = readExact($s, 8);
    if (strlen($hdr) < 8) {
        return null;
    }
    $len = HeaderFrame::u32($hdr, 0);
    if ($len < 8 || $len > 1048576) {
        return null;
    }
    $body = "";
    if ($len > 8) {
        $body = readExact($s, $len - 8);
    }
    return [HeaderFrame::u16($hdr, 4), ord($hdr[6]), $body];
}

$s = conn($port);

// 登录（系统消息：永远是原样字符串）
send($s, Msg::LOGIN, "tok-1001");
$r = recvFrame($s);
okk("登录成功（系统消息不受 payload 影响）", $r !== null && $r[0] === Msg::LOGIN_OK && $r[2] === "1001",
    "body=" . ($r[2] ?? ""));

// 心跳（系统消息）
send($s, Msg::PING, "hb");
$r = recvFrame($s);
okk("心跳 PONG", $r !== null && $r[0] === Msg::PONG && $r[2] === "hb");

if ($mode === "json") {
    // 业务消息：发 JSON，服务端应解析成数组再原样回写
    $payload = ["uid" => 1001, "hp" => 88, "name" => "测试"];
    send($s, Msg::ECHO_REQ, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $r = recvFrame($s);
    $back = ($r !== null) ? json_decode($r[2], true) : null;
    okk("JSON 业务消息往返", is_array($back) && $back["hp"] === 88 && $back["name"] === "测试",
        "resp=" . ($r[2] ?? ""));

    // 嵌套结构
    $nested = ["a" => ["b" => [1, 2, 3]], "flag" => true];
    send($s, Msg::ECHO_REQ, json_encode($nested));
    $r = recvFrame($s);
    $back = ($r !== null) ? json_decode($r[2], true) : null;
    okk("JSON 嵌套结构往返", is_array($back) && $back["a"]["b"] === [1, 2, 3] && $back["flag"] === true);

    // 非法 JSON → 服务端应回错误或断开（不应崩）
    send($s, Msg::ECHO_REQ, "{bad json");
    $r = recvFrame($s);
    $survived = ($r === null) || ($r[0] === Msg::ERR);
    okk("非法 JSON 不会崩服务", $survived, $r === null ? "连接被断开" : "msgId=" . $r[0]);
} else {
    $payload = "raw-payload-" . time();
    send($s, Msg::ECHO_REQ, $payload);
    $r = recvFrame($s);
    okk("raw 业务消息往返", $r !== null && $r[0] === Msg::ECHO_RESP && $r[2] === $payload);

    // 二次元数据：raw 模式下二进制内容应逐字节一致
    $bin = "\x00\x01\x02\xff\xfe";
    send($s, Msg::ECHO_REQ, $bin);
    $r = recvFrame($s);
    okk("raw 二进制逐字节一致", $r !== null && $r[2] === $bin);
}

fclose($s);
echo PHP_EOL . "结果: " . $pass . " 通过 / " . $fail . " 失败" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
