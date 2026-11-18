<?php

/**
 * WebSocket 传输测试。
 *
 * 验证 WS 只当传输：payload 仍是 TSF 二进制帧，同一套 Gateway 正常工作。
 * 用 Swoole 协程客户端（解释器已带 swoole 扩展）。
 *
 * 用法：php85 ws-test.php <port>
 */

require_once __DIR__ . "/../tsf/src/Protocol/Contract/DecodedFrame.php";
require_once __DIR__ . "/../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../tsf/src/Protocol/Frame/HeaderFrame.php";
require_once __DIR__ . "/../tsf/src/Protocol/Msg.php";

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Msg;

$port = (int) ($argv[1] ?? 9010);
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

/** 从二进制流里解出第一帧，返回 [msgId, body, consumed] */
function parseOne(string $buf): ?array
{
    $f = HeaderFrame::parse($buf);
    if ($f === null) {
        return null;
    }
    return [$f->msgId, $f->body, $f->consumed];
}

Coroutine\run(function () use ($port) {
    $cli = new Client("127.0.0.1", $port);
    $cli->set(["timeout" => 3.0]);
    $ok = $cli->upgrade("/");
    okk("WebSocket 握手成功", $ok, $ok ? "status=" . $cli->statusCode : "status=" . $cli->statusCode);
    if (!$ok) {
        return;
    }

    // 1) 未登录发业务 → ERR
    $cli->push(HeaderFrame::build(Msg::ECHO_REQ, "before-login"), WEBSOCKET_OPCODE_BINARY);
    $f = $cli->recv(2.0);
    $p = ($f !== false && $f !== null && $f->data !== "") ? parseOne((string) $f->data) : null;
    okk("未登录业务消息被拒", $p !== null && $p[0] === Msg::ERR, "msgId=" . ($p[0] ?? "null"));

    // 2) 登录
    $cli->push(HeaderFrame::build(Msg::LOGIN, "tok-1001"), WEBSOCKET_OPCODE_BINARY);
    $f = $cli->recv(2.0);
    $p = ($f !== false && $f !== null && $f->data !== "") ? parseOne((string) $f->data) : null;
    okk("登录成功", $p !== null && $p[0] === Msg::LOGIN_OK && $p[1] === "1001", "body=" . ($p[1] ?? ""));

    // 3) 业务回显
    $payload = "ws-echo-" . time();
    $cli->push(HeaderFrame::build(Msg::ECHO_REQ, $payload), WEBSOCKET_OPCODE_BINARY);
    $f = $cli->recv(2.0);
    $p = ($f !== false && $f !== null && $f->data !== "") ? parseOne((string) $f->data) : null;
    okk("业务回显一致", $p !== null && $p[0] === Msg::ECHO_RESP && $p[1] === $payload, "body=" . ($p[1] ?? ""));

    // 4) 心跳
    $cli->push(HeaderFrame::build(Msg::PING, "hb"), WEBSOCKET_OPCODE_BINARY);
    $f = $cli->recv(2.0);
    $p = ($f !== false && $f !== null && $f->data !== "") ? parseOne((string) $f->data) : null;
    okk("心跳 PONG", $p !== null && $p[0] === Msg::PONG, "msgId=" . ($p[0] ?? "null"));

    // 5) 一条 WS 消息里塞两帧（模拟粘包）——应被拆成两次派发
    $two = HeaderFrame::build(Msg::ECHO_REQ, "A") . HeaderFrame::build(Msg::ECHO_REQ, "B");
    $cli->push($two, WEBSOCKET_OPCODE_BINARY);
    $first = $cli->recv(2.0);
    $second = $cli->recv(2.0);
    $p1 = ($first !== false && $first !== null) ? parseOne((string) $first->data) : null;
    $p2 = ($second !== false && $second !== null) ? parseOne((string) $second->data) : null;
    okk("WS 内多帧被正确拆分", $p1 !== null && $p2 !== null && $p1[1] === "A" && $p2[1] === "B",
        "收到 " . ($p1[1] ?? "?") . " / " . ($p2[1] ?? "?"));

    // 6) 非法帧头 → 服务端断开
    $cli->push("\x00\x00\x00\x01xxxxxxxx", WEBSOCKET_OPCODE_BINARY);
    $f = $cli->recv(1.0);
    $closed = ($f === false || $f === null || (isset($f->opcode) && $f->opcode === WEBSOCKET_OPCODE_CLOSE));
    usleep(300000);
    okk("非法帧导致连接关闭", !$cli->connected || $closed);

    $cli->close();
});

echo PHP_EOL . "结果: " . $pass . " 通过 / " . $fail . " 失败" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
