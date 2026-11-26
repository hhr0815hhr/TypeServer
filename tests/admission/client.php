<?php

// 连接准入的客户端（解释执行）。用法：php85 client.php <场景>
//
//   login-timeout  只发心跳不登录 → 应当在登录时限之后被断开
//   login-ok       登录之前等一会儿、登录之后一直发心跳 → 不该被断开
//   fail-per-conn  同一条连接连续登录失败 → 达到上限被断开
//   peer-rate      同一来源多条未登录连接猛发 → 收到的回显受来源桶限制
//   peer-ban       同一来源多条连接各失败几次 → 之后用**正确的** token 也登录不了
//   after-ban      封禁期满 → 正确的 token 能登录
// 行尾打 OK / FAIL，run.sh 靠这个判定。

const PORT = 9422;
const SECRET = "adm-secret";

function frame(int $id, string $body): string
{
    return pack("NnCC", 8 + strlen($body), $id, 0, 0) . $body;
}

/** 读一帧，返回 [msgId, body]；对端关闭或超时返回 null */
function readFrame($s, float $timeout = 1.0): ?array
{
    $buf = "";
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        $d = @fread($s, 4096);
        if ($d === false || ($d === "" && feof($s))) {
            return null;
        }
        $buf .= $d;
        if (strlen($buf) >= 8) {
            $len = unpack("N", substr($buf, 0, 4))[1];
            if (strlen($buf) >= $len) {
                return [unpack("n", substr($buf, 4, 2))[1], substr($buf, 8, $len - 8)];
            }
        }
        usleep(3000);
    }
    return null;
}

function token(int $uid): string
{
    $now = time();
    $payload = $uid . "." . $now . "." . ($now + 300);
    return $payload . "." . hash_hmac("sha256", $payload, SECRET);
}

function open(): mixed
{
    return stream_socket_client("tcp://127.0.0.1:" . PORT, $errno, $errstr, 2.0);
}

function closed($s): bool
{
    return feof($s);
}

$scene = $argv[1] ?? "";

if ($scene === "login-timeout") {
    $s = open();
    $t0 = microtime(true);
    $pongs = 0;
    $eofAt = 0.0;
    while (microtime(true) - $t0 < 6.0) {
        fwrite($s, frame(100, ""));
        $r = readFrame($s, 0.3);
        if ($r === null) {
            $eofAt = microtime(true) - $t0;
            break;
        }
        $pongs++;
        usleep(200000);
    }
    // 配置：登录时限 2 秒，清扫 1 秒一次 → 2–3 秒之间断开
    $ok = $eofAt > 1.5 && $eofAt < 4.5;
    echo "一直发心跳但不登录，在 " . round($eofAt, 1) . " 秒时被断开（之前收到 $pongs 个心跳回复）: " . ($ok ? "OK" : "FAIL") . "\n";
    if ($pongs === 0) {
        echo "对照：登录前心跳是通的: FAIL\n";
    } else {
        echo "对照：登录前心跳是通的: OK\n";
    }
} elseif ($scene === "login-ok") {
    $s = open();
    $t0 = microtime(true);
    $alive = true;
    $pongs = 0;
    // 先发 1 秒心跳（在登录时限之内），然后登录
    while (microtime(true) - $t0 < 1.0) {
        fwrite($s, frame(100, ""));
        readFrame($s, 0.3);
        usleep(200000);
    }
    fwrite($s, frame(1, token(77)));
    $r = readFrame($s, 1.0);
    $loginOk = $r !== null && $r[0] === 2;   // LOGIN_OK
    // 登录之后再发 3.5 秒心跳：早过了登录时限，不该被断
    $t1 = microtime(true);
    while (microtime(true) - $t1 < 3.5) {
        fwrite($s, frame(100, ""));
        if (readFrame($s, 0.3) === null) {
            $alive = false;
            break;
        }
        $pongs++;
        usleep(200000);
    }
    echo "登录成功: " . ($loginOk ? "OK" : "FAIL") . "\n";
    echo "登录之后过了登录时限仍然连着（又收 " . $pongs . " 个心跳回复）: " . ($alive && $pongs > 8 ? "OK" : "FAIL") . "\n";
} elseif ($scene === "fail-per-conn") {
    $s = open();
    $fails = 0;
    $closed = false;
    for ($i = 0; $i < 3; $i++) {
        fwrite($s, frame(1, "bogus"));
        $r = readFrame($s, 0.8);
        if ($r === null) {
            $closed = true;
            break;
        }
        $fails++;
    }
    // 再确认一下确实断了
    if (!$closed) {
        fwrite($s, frame(100, ""));
        $closed = readFrame($s, 0.8) === null;
    }
    // 第 3 次失败服务端会先回 LOGIN_FAIL 再断开，所以可能是 2 次也可能是 3 次
    echo "前两次拿到 LOGIN_FAIL: " . ($fails === 2 || $fails === 3 ? "OK" : "FAIL") . "  [收到 $fails 次]\n";
    echo "第 3 次失败之后连接被断开（上限 3）: " . ($closed ? "OK" : "FAIL") . "\n";
} elseif ($scene === "peer-rate") {
    // 6 条连接，每条发 10 个心跳 = 60 帧；来源桶容量 20、每秒补 20
    $conns = [];
    for ($i = 0; $i < 6; $i++) {
        $conns[] = open();
    }
    foreach ($conns as $c) {
        stream_set_blocking($c, false);
    }
    $t0 = microtime(true);
    foreach ($conns as $c) {
        for ($i = 0; $i < 10; $i++) {
            @fwrite($c, frame(100, ""));
        }
    }
    $got = 0;
    while (microtime(true) - $t0 < 0.4) {
        foreach ($conns as $c) {
            $d = @fread($c, 65536);
            if (is_string($d)) {
                $got += intdiv(strlen($d), 8);
            }
        }
        usleep(5000);
    }
    // 0.4 秒内：桶 20 + 最多补 8 个左右。没有来源限流的话是 60
    echo "同一来源 60 帧未登录流量，0.4 秒内只回了 $got 帧（远少于 60）: " . ($got <= 40 && $got >= 15 ? "OK" : "FAIL") . "\n";
    foreach ($conns as $c) {
        fclose($c);
    }
    usleep(150000);
} elseif ($scene === "peer-ban") {
    // 每条连接失败 2 次（低于单连接上限 3），4 条 = 8 次 ≥ 来源上限 7
    for ($i = 0; $i < 4; $i++) {
        $s = open();
        fwrite($s, frame(1, "bogus"));
        readFrame($s, 0.5);
        fwrite($s, frame(1, "bogus"));
        readFrame($s, 0.5);
        fclose($s);
        usleep(30000);
    }
    usleep(400000);
    $s = open();
    fwrite($s, frame(1, token(88)));
    $r = readFrame($s, 1.0);
    echo "封禁之后用**正确的** token 也登录不了: " . ($r !== null && $r[0] === 3 ? "OK" : "FAIL") . "  [" . ($r === null ? "无响应" : "msgId=" . $r[0]) . "]\n";
    fclose($s);
} elseif ($scene === "after-ban") {
    $s = open();
    fwrite($s, frame(1, token(89)));
    $r = readFrame($s, 1.0);
    echo "封禁期满（3 秒）之后正确 token 能登录: " . ($r !== null && $r[0] === 2 ? "OK" : "FAIL") . "  [" . ($r === null ? "无响应" : "msgId=" . $r[0]) . "]\n";
    fclose($s);
} else {
    echo "未知场景 [$scene]: FAIL\n";
    exit(1);
}
