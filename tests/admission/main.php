<?php

use TSF\Gateway\Gateway;
use TSF\Gateway\TokenAuth;
use TSF\Kernel\Bootstrap;
use TSF\Kernel\Config;
use TSF\Kernel\Logger;
use TSF\Protocol\Frame\HeaderFrame;
use TSF\Protocol\Msg;
use TSF\Transport\Tcp\TcpServer;

/**
 * 连接准入。两种模式（环境变量 ADM_MODE）：
 *
 *   server  起一个**用 Gateway::assemble() 装配**的网关（端口 9422），准入参数来自 config/tsf.json。
 *           走装配入口而不是手写五件套：这样"默认值只有一份"的入口和准入逻辑一起被验证。
 *   token   TokenAuth 的作废 / 轮换 / 旧格式开关，以及 assemble() 读出来的默认值。
 */
const PORT = 9422;
const SECRET = "adm-secret";

final class T
{
    public static int $pass = 0;

    public static int $fail = 0;

    public static function ok(bool $cond, string $label, string $detail = ""): void
    {
        if ($cond) {
            self::$pass++;
            echo "  OK   " . $label . PHP_EOL;
        } else {
            self::$fail++;
            echo "  FAIL " . $label . ($detail === "" ? "" : "  → " . $detail) . PHP_EOL;
        }
    }
}

/** 用 3 段过渡格式手工造一个 token（sign() 已经不签这种格式了） */
function legacy3(string $secret, int $uid, int $exp): string
{
    $payload = $uid . "." . $exp;
    return $payload . "." . hash_hmac("sha256", $payload, $secret);
}

function runToken(): void
{
    // assemble() 读的是 Config：先把这个套件自己的配置加载进来
    Config::load(__DIR__ . "/config", "prod");
    $auth = new TokenAuth(["tok-static" => 9001], SECRET);
    $now = time();

    echo "== [1] 当前格式：带签发时刻 ==" . PHP_EOL;
    $t1 = $auth->sign(5, 300);
    T::ok(count(explode(".", $t1)) === 4, "签出来是 4 段（uid.iat.exp.sig）", $t1);
    T::ok($auth->resolve($t1) === 5, "能验回 uid");
    // 过期的要手工造：sign() 的 ttl<=0 走的是"永不过期"的老格式，不是"已经过期"
    $expiredPayload = "5." . (string) ($now - 400) . "." . (string) ($now - 300);
    $expired = $expiredPayload . "." . hash_hmac("sha256", $expiredPayload, SECRET);
    T::ok($auth->resolve($expired) === 0, "过期的验不回");
    $parts = explode(".", $t1);
    T::ok($auth->resolve($parts[0] . "." . $parts[1] . "." . ($parts[2] + 99999) . "." . $parts[3]) === 0, "改了 exp 就失效（签名覆盖 iat 和 exp）");

    echo PHP_EOL . "== [2] 按玩家作废 ==" . PHP_EOL;
    $other = $auth->sign(6, 300);
    usleep(1100000);
    $auth->revokeUid(5);
    T::ok($auth->resolve($t1) === 0, "作废之后旧 token 失效");
    T::ok($auth->resolve($other) === 6, "别的玩家不受影响");
    $fresh = $auth->sign(5, 300);
    T::ok($auth->resolve($fresh) === 5, "重新登录拿到的新 token 照常可用");

    echo PHP_EOL . "== [3] 整体作废 ==" . PHP_EOL;
    usleep(1100000);
    $auth->revokeAll();
    T::ok($auth->resolve($fresh) === 0, "全体作废之后，之前签的都失效");
    T::ok($auth->resolve($other) === 0, "别的玩家的也失效");
    T::ok($auth->resolve("tok-static") === 9001, "静态表里的 token 不受影响");
    $after = $auth->sign(7, 300);
    T::ok($auth->resolve($after) === 7, "作废之后新签的可用");
    $l3 = legacy3(SECRET, 8, $now + 3000);
    T::ok($auth->resolve($l3) === 0, "没有签发时刻的 3 段旧格式，一旦作废过就一律不认");

    echo PHP_EOL . "== [4] 旧格式开关 ==" . PHP_EOL;
    $strict = new TokenAuth([], SECRET, false, false);
    $lax = new TokenAuth([], SECRET, false, true);
    $l2 = $lax->sign(9, 0);
    T::ok(count(explode(".", $l2)) === 2, "ttl<=0 签的是 2 段永不过期格式");
    T::ok($lax->resolve($l2) === 9, "默认仍然接受 2 段格式（不打断已有部署）");
    T::ok($strict->resolve($l2) === 0, "关掉之后 2 段格式被拒");
    T::ok($strict->resolve(legacy3(SECRET, 9, $now + 3000)) === 9, "关掉的只是永不过期那种；3 段过渡格式照认");
    $strict->allowLegacy(true);
    T::ok($strict->resolve($l2) === 9, "allowLegacy(true) 可以再打开");

    echo PHP_EOL . "== [5] 密钥轮换 ==" . PHP_EOL;
    $old = new TokenAuth([], "old-secret");
    $oldTok = $old->sign(11, 300);
    $rotated = new TokenAuth([], "new-secret");
    $rotated->addPreviousSecret("old-secret");
    T::ok($rotated->resolve($oldTok) === 11, "登记了旧密钥：旧 token 在过期前继续有效");
    $newTok = $rotated->sign(11, 300);
    T::ok($old->resolve($newTok) === 0, "签发只用当前密钥");
    $plain = new TokenAuth([], "new-secret");
    T::ok($plain->resolve($oldTok) === 0, "没登记旧密钥就不认旧 token");

    echo PHP_EOL . "== [6] 签发时刻在未来的 token 被拒 ==" . PHP_EOL;
    $payload = "12." . (string) ($now + 10000) . "." . (string) ($now + 20000);
    $forged = $payload . "." . hash_hmac("sha256", $payload, SECRET);
    T::ok($auth->resolve($forged) === 0, "签名对，但签发时刻在未来：拒（否则能绕过之后所有的作废）");

    echo PHP_EOL . "== [7] Gateway::assemble() 读配置 ==" . PHP_EOL;
    $frame = new HeaderFrame();
    $server = new TcpServer("127.0.0.1", PORT, $frame, ["max_conn" => 64]);
    $gw = Gateway::assemble($server, null);
    T::ok($gw->loginTimeout() === 2, "登录时限来自 server.login_timeout_sec", "got=" . $gw->loginTimeout());
    $st = $gw->stats();
    T::ok(isset($st["login_timeout_closed"], $st["login_banned"], $st["peer_dropped"]), "新增的计数都在 stats() 里");
    T::ok(!$gw->isPeerBanned("1.2.3.4"), "还没见过任何来源");

    // 没有任何 server.* 配置时走默认值：重新加载一份空配置
    $tmp = "/tmp/tsf_admission_cfg";
    if (!is_dir($tmp)) {
        mkdir($tmp, 0777, true);
    }
    file_put_contents($tmp . "/tsf.json", "{\"app\":{\"name\":\"x\"}}");
    Config::load($tmp, "prod");
    $server2 = new TcpServer("127.0.0.1", PORT + 1, $frame, ["max_conn" => 64]);
    $gw2 = Gateway::assemble($server2, null);
    T::ok($gw2->loginTimeout() === 30, "没配的时候登录时限是默认的 30 秒", "got=" . $gw2->loginTimeout());
}

function runServer(): void
{
    Bootstrap::boot(__DIR__, "app");
    $server = new TcpServer("127.0.0.1", PORT, new HeaderFrame(), [
        "max_conn" => 256,
        "max_wait_time" => 0,
    ]);
    $gw = Gateway::assemble($server, null);
    $gw->on(Msg::ECHO_REQ, function (int $fd, int $uid, string $body) use ($gw): void {
        $gw->send($fd, Msg::ECHO_RESP, $body);
    });
    Logger::info("admission ready", ["port" => PORT, "login_timeout" => $gw->loginTimeout()]);
    $gw->boot();
    $server->start();
}

function main(): void
{
    $mode = (string) getenv("ADM_MODE");
    if ($mode === "token") {
        runToken();
        echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
        if (T::$fail > 0) {
            exit(1);
        }
        return;
    }
    runServer();
}
