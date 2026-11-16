<?php

/**
 * M1 功能测试：鉴权 / 顶号 / 心跳 / 限流。
 *
 * 用法：php85 m1-test.php <port> <case>
 *   case: auth | kick | heartbeat | rate | all
 *
 * 读帧一律"按长度精确读"——按块读会在服务端足够快时丢帧（踩过）。
 */

require_once __DIR__ . "/../tsf/src/Protocol/Contract/FrameCodec.php";
require_once __DIR__ . "/../tsf/src/Protocol/Frame/HeaderFrame.php";
require_once __DIR__ . "/../tsf/src/Protocol/Msg.php";

use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Msg;

$port = (int) ($argv[1] ?? 9001);
$case = $argv[2] ?? "all";
$hbSec = (int) ($argv[3] ?? 3);

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
    stream_set_timeout($s, 5);
    return $s;
}

function send($s, int $msgId, string $body = ""): void
{
    @fwrite($s, HeaderFrame::build($msgId, $body));
}

function readExact($s, int $n): string
{
    $buf = "";
    while (strlen($buf) < $n) {
        $chunk = @fread($s, $n - strlen($buf));
        if ($chunk === false || $chunk === "") {
            return "";
        }
        $buf .= $chunk;
    }
    return $buf;
}

/** @return array<int,mixed>|null [msgId, flags, body] */
function recvFrame($s, int $timeoutMs = 2000): ?array
{
    $sec = (int) ($timeoutMs / 1000);
    $usec = ($timeoutMs % 1000) * 1000;
    stream_set_timeout($s, $sec, $usec);
    $hdr = readExact($s, 8);
    if (strlen($hdr) < 8) {
        return null;
    }
    $len = HeaderFrame::u32($hdr, 0);
    if ($len < 8 || $len > 1024 * 1024) {
        return null;
    }
    $body = "";
    if ($len > 8) {
        $body = readExact($s, $len - 8);
        if (strlen($body) !== $len - 8) {
            return null;
        }
    }
    return [HeaderFrame::u16($hdr, 4), ord($hdr[6]), $body];
}

/**
 * 非破坏性判断连接是否已关闭。
 *
 * 不能用 "fread 返回空串就是断开"——PHP 的 fread 在**读超时**时也返回空串，
 * 于是"1 秒内没数据"会被误判成"连接已断"（我第一版就踩了这个，导致认为
 * 心跳 1.2s 就断开）。正确做法是非阻塞读 + 检查 feof。
 */
function isClosed($s): bool
{
    stream_set_blocking($s, false);
    $d = @fread($s, 8192);
    $eof = feof($s);
    stream_set_blocking($s, true);
    if ($eof) {
        return true;
    }
    return ($d === false && $eof);
}

// ---------------- 用例 ----------------

function caseAuth(int $port): void
{
    echo "[auth] 鉴权流程" . PHP_EOL;
    $s = conn($port);

    // 1) 未登录发业务消息
    send($s, Msg::ECHO_REQ, "before-login");
    $r = recvFrame($s);
    okk("未登录业务消息被拒", $r !== null && $r[0] === Msg::ERR, "msgId=" . ($r[0] ?? "null"));

    // 2) 错误 token
    send($s, Msg::LOGIN, "bad-token");
    $r = recvFrame($s);
    okk("错误 token 登录失败", $r !== null && $r[0] === Msg::LOGIN_FAIL);

    // 3) 正确 token
    send($s, Msg::LOGIN, "tok-1001");
    $r = recvFrame($s);
    okk("正确 token 登录成功", $r !== null && $r[0] === Msg::LOGIN_OK && $r[2] === "1001", "body=" . ($r[2] ?? ""));

    // 4) 登录后 uid 已绑定
    send($s, 1003, "");
    $r = recvFrame($s);
    okk("登录后 uid 绑定正确", $r !== null && $r[0] === 1004 && $r[2] === "uid=1001", "body=" . ($r[2] ?? ""));

    // 5) 登录后业务消息正常
    send($s, Msg::ECHO_REQ, "after-login");
    $r = recvFrame($s);
    okk("登录后业务消息正常", $r !== null && $r[0] === Msg::ECHO_RESP && $r[2] === "after-login");

    // 6) 心跳
    send($s, Msg::PING, "hb-1");
    $r = recvFrame($s);
    okk("PING 收到 PONG", $r !== null && $r[0] === Msg::PONG && $r[2] === "hb-1");

    fclose($s);
}

function caseKick(int $port): void
{
    echo "[kick] 顶号（同账号二次登录）" . PHP_EOL;
    $c1 = conn($port);
    send($c1, Msg::LOGIN, "tok-1002");
    $r = recvFrame($c1);
    okk("旧连接登录成功", $r !== null && $r[0] === Msg::LOGIN_OK);

    $c2 = conn($port);
    send($c2, Msg::LOGIN, "tok-1002");
    $r2 = recvFrame($c2);
    okk("新连接登录成功", $r2 !== null && $r2[0] === Msg::LOGIN_OK);

    // 旧连接应收到 KICKED，随后断开（FIN 可能比 KICKED 晚到，需短暂轮询）
    $r1 = recvFrame($c1, 2000);
    okk("旧连接收到 KICKED", $r1 !== null && $r1[0] === Msg::KICKED, "msgId=" . ($r1[0] ?? "null"));
    $closedOk = false;
    for ($i = 0; $i < 20; $i++) {
        if (isClosed($c1)) {
            $closedOk = true;
            break;
        }
        usleep(50000);
    }
    okk("旧连接被断开", $closedOk);

    // 新连接仍然可用
    send($c2, 1003, "");
    $r = recvFrame($c2);
    okk("新连接仍可用", $r !== null && $r[0] === 1004 && $r[2] === "uid=1002", "body=" . ($r[2] ?? ""));

    fclose($c1);
    fclose($c2);
}

function caseHeartbeat(int $port, int $hbSec): void
{
    echo "[heartbeat] 空闲断开（阈值 " . $hbSec . "s）" . PHP_EOL;

    $idle = conn($port);
    send($idle, Msg::LOGIN, "tok-1001");
    $r = recvFrame($idle);
    okk("静默连接登录成功", $r !== null && $r[0] === Msg::LOGIN_OK);

    $alive = conn($port);
    send($alive, Msg::LOGIN, "tok-1002");
    $r = recvFrame($alive);
    okk("保活连接登录成功", $r !== null && $r[0] === Msg::LOGIN_OK);

    $t0 = microtime(true);
    $idleClosedAt = 0.0;
    $alivePongs = 0;
    $aliveDead = false;

    while (microtime(true) - $t0 < $hbSec + 4.0) {
        // 保活连接：发心跳、必须收到 PONG（顺带验证心跳链路）
        send($alive, Msg::PING, "ka");
        $rr = recvFrame($alive, 500);
        if ($rr !== null && $rr[0] === Msg::PONG) {
            $alivePongs++;
        } elseif (isClosed($alive)) {
            $aliveDead = true;
            break;
        }

        if ($idleClosedAt <= 0.0 && isClosed($idle)) {
            $idleClosedAt = microtime(true) - $t0;
        }
        usleep(200000);
    }

    okk("空闲连接被断开", $idleClosedAt > 0.0,
        $idleClosedAt > 0.0 ? sprintf("%.1fs 后断开", $idleClosedAt) : "未被断开");
    $timingOk = $idleClosedAt >= (float) $hbSec - 0.5 && $idleClosedAt <= (float) $hbSec + 3.0;
    okk("断开时机合理", $timingOk, sprintf("阈值 %ds，实测 %.1fs", $hbSec, $idleClosedAt));
    okk("持续心跳的连接未被断开", !$aliveDead && $alivePongs > 0, "收到 PONG " . $alivePongs . " 次");

    fclose($idle);
    fclose($alive);
}

function caseRate(int $port): void
{
    echo "[rate] 限流（突发 200 帧）" . PHP_EOL;
    $s = conn($port);
    send($s, Msg::LOGIN, "tok-1001");
    $r = recvFrame($s);
    okk("限流测试连接登录成功", $r !== null && $r[0] === Msg::LOGIN_OK);

    $n = 200;
    for ($i = 0; $i < $n; $i++) {
        send($s, Msg::ECHO_REQ, "x");
    }

    $echo = 0;
    $tooFast = 0;
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $r = recvFrame($s, 300);
        if ($r === null) {
            break;
        }
        if ($r[0] === Msg::ECHO_RESP) {
            $echo++;
        } elseif ($r[0] === Msg::TOO_FAST) {
            $tooFast++;
        }
    }

    okk("部分请求被限流", $tooFast > 0, "放行 " . $echo . " / 丢弃提示 " . $tooFast);
    okk("不是全部放行", $echo < $n, "放行 " . $echo . " / 共 " . $n);
    okk("不是全部丢弃", $echo > 0, "放行 " . $echo);
    // burst=100，其中 1 个令牌被 LOGIN 消耗 → 期望放行 99 起；
    // 200 帧在几百毫秒内发完，按 20/s 补充最多再多几十个，上限取 190。
    okk("放行数在合理区间(95~190)", $echo >= 95 && $echo <= 190, "放行 " . $echo);

    fclose($s);
}

// ---------------- 调度 ----------------

if ($case === "auth" || $case === "all") {
    caseAuth($port);
}
if ($case === "kick" || $case === "all") {
    caseKick($port);
}
if ($case === "heartbeat" || $case === "all") {
    caseHeartbeat($port, $hbSec);
}
if ($case === "rate" || $case === "all") {
    caseRate($port);
}

echo PHP_EOL . "结果: " . $pass . " 通过 / " . $fail . " 失败" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
