<?php

/**
 * T6-05 验证：独立 GM 通道（鉴权 + 审计 + 防爆破）。
 *
 * 客户端和服务端在同一进程里：服务端在 `$s->tick()` 里 poll socket，
 * 所以测试必须**边 tick 边收发**，不能阻塞在 fgets 上——那样服务端永远不推进。
 */

use TSF\Gm\Registry;
use TSF\Gm\Result;
use TSF\Gm\Server;
use TSF\Kernel\Logger;
use TSF\Service\Scheduler;

const PORT = 19701;
const TOKEN = "s3cr3t-token-for-test";
const LOG_FILE = "/tmp/gm_test.log";

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

    public static function eq(int|string|float|bool $got, int|string|float|bool $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }

    public static function has(string $hay, string $needle, string $label): void
    {
        self::ok(str_contains($hay, $needle), $label, "找不到 [" . $needle . "]");
    }
}

/** @return array{0: int, 1: string} [状态码, body] */
function http(Scheduler $s, string $method, string $path, string $body = "", array $headers = []): array
{
    $raw = $method . " " . $path . " HTTP/1.1\r\nHost: 127.0.0.1\r\n";
    foreach ($headers as $k => $v) {
        $raw .= $k . ": " . $v . "\r\n";
    }
    if ($body !== "") {
        $raw .= "Content-Length: " . strlen($body) . "\r\n";
    }
    $raw .= "Connection: close\r\n\r\n" . $body;

    $sock = @stream_socket_client("tcp://127.0.0.1:" . PORT, $errno, $errstr, 1.0);
    if ($sock === false) {
        return [0, "connect failed: " . $errstr];
    }
    @fwrite($sock, $raw);
    stream_set_blocking($sock, false);

    $buf = "";
    $deadline = Scheduler::nowMs() + 500;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        $chunk = @fread($sock, 8192);
        if ($chunk !== false && $chunk !== "") {
            $buf .= $chunk;
            continue;
        }
        if (feof($sock)) {
            break;
        }
        usleep(300);
    }
    @fclose($sock);

    $status = 0;
    if (preg_match('/^HTTP\/1\.1 (\d+)/', $buf, $m) === 1) {
        $status = (int) $m[1];
    }
    $pos = strpos($buf, "\r\n\r\n");
    $respBody = $pos === false ? "" : substr($buf, $pos + 4);
    return [$status, $respBody];
}

function logText(): string
{
    $s = @file_get_contents(LOG_FILE);
    return $s === false ? "" : $s;
}

function boot(): array
{
    @unlink(LOG_FILE);
    Logger::init("gm-test", LOG_FILE, Logger::DEBUG, false);
    Registry::clear();
    Scheduler::reset();
    $s = Scheduler::instance();
    $addr = $s->spawnOf(function (): Server {
        return new Server("127.0.0.1", PORT, TOKEN, 2);
    });
    return [$s, $addr];
}

function caseEmptyToken(): void
{
    echo "== [1] 空 token 必须拒绝启动 ==" . PHP_EOL;
    $srv = new Server("127.0.0.1", 19799, "", 2);
    $caught = "";
    try {
        $srv->onStart();
    } catch (\RuntimeException $e) {
        $caught = $e->getMessage();
    }
    T::ok($caught !== "", "空 token 抛异常");
    T::has($caught, "token", "错误信息说清原因");
}

function caseBasics(Scheduler $s): void
{
    echo "== [2] 路由与鉴权 ==" . PHP_EOL;

    [$st, $b] = http($s, "GET", "/health");
    T::eq($st, 200, "/health 200");
    T::has($b, "\"up\":true", "/health 返回数据");

    [$st, $b] = http($s, "GET", "/nope");
    T::eq($st, 404, "未知路径 404");

    [$st, $b] = http($s, "GET", "/gm");
    T::eq($st, 405, "GET /gm 405（只接受 POST）");

    [$st, $b] = http($s, "POST", "/gm", "{\"cmd\":\"x\"}");
    T::eq($st, 401, "没带 token 401");

    [$st, $b] = http($s, "POST", "/gm", "{\"cmd\":\"x\"}", ["X-GM-Token" => "wrong"]);
    T::eq($st, 401, "token 不对 401");
    T::has($b, "鉴权失败", "响应里不区分缺失与错误");

    [$st, $b] = http($s, "POST", "/gm", "{\"cmd\":\"x\"}", ["X-GM-Token" => "s3cr3t-token-for-tesT"]);
    T::eq($st, 401, "token 差一个字符也 401（定长比较没有前缀通过）");

    [$st, $b] = http($s, "POST", "/gm", "{\"cmd\":\"x\"}", ["Authorization" => "Bearer " . TOKEN]);
    T::eq($st, 200, "Bearer 头也认");
}

function caseDispatch(Scheduler $s): void
{
    echo "== [3] 派发 ==" . PHP_EOL;
    Registry::clear();
    Registry::on("player.ban", function (array $args): Result {
        $uid = isset($args["uid"]) ? (int) $args["uid"] : 0;
        if ($uid <= 0) {
            return Result::fail("uid 必填");
        }
        return Result::ok("{\"uid\":" . $uid . ",\"banned\":true}");
    }, "封禁玩家");

    Registry::on("boom", function (array $args): Result {
        throw new \RuntimeException("下层炸了");
    });

    [$st, $b] = http($s, "POST", "/gm", "{\"cmd\":\"player.ban\",\"args\":{\"uid\":1001}}", ["X-GM-Token" => TOKEN]);
    T::eq($st, 200, "成功 200");
    T::has($b, "\"ok\":true", "ok:true");
    T::has($b, "\"uid\":1001", "data 带回参数");

    [$st, $b] = http($s, "POST", "/gm", "{\"cmd\":\"player.ban\",\"args\":{}}", ["X-GM-Token" => TOKEN]);
    T::has($b, "\"ok\":false", "参数不合法时 ok:false");
    T::has($b, "uid 必填", "错误信息透传");

    [$st, $b] = http($s, "POST", "/gm", "{\"cmd\":\"nope\"}", ["X-GM-Token" => TOKEN]);
    T::has($b, "未知指令", "未知指令");

    // handler 抛异常：要么 500、要么 ok:false——重点是**服务不能倒**
    [$st, $b] = http($s, "POST", "/gm", "{\"cmd\":\"boom\"}", ["X-GM-Token" => TOKEN]);
    T::has($b, "\"ok\":false", "handler 抛异常被兜住");
    T::has($b, "指令执行失败", "错误信息说明是执行失败");

    [$st, $b] = http($s, "POST", "/gm", "{\"cmd\":\"player.ban\",\"args\":{\"uid\":7}}", ["X-GM-Token" => TOKEN]);
    T::has($b, "\"ok\":true", "抛异常之后服务仍可用");
}

function caseBadBody(Scheduler $s): void
{
    echo "== [4] 畸形请求 ==" . PHP_EOL;

    [$st, $b] = http($s, "POST", "/gm", "not json", ["X-GM-Token" => TOKEN]);
    T::eq($st, 400, "非 JSON body 400");

    [$st, $b] = http($s, "POST", "/gm", "[1,2,3]", ["X-GM-Token" => TOKEN]);
    T::eq($st, 400, "JSON 但不是对象 → 400");

    // 超长 body：Content-Length 直接超上限，服务端应当立刻 413 而不是等数据
    $big = str_repeat("a", 70000);
    [$st, $b] = http($s, "POST", "/gm", $big, ["X-GM-Token" => TOKEN]);
    T::eq($st, 413, "超大 body 413");
}

function caseAudit(): void
{
    echo "== [5] 审计日志 ==" . PHP_EOL;
    $log = logText();

    T::has($log, "\"msg\":\"gm: player.ban\"", "记了指令名");
    T::has($log, "\"cmd\":\"player.ban\"", "ctx 里有 cmd");
    T::has($log, "\"args\":\"uid=1001\"", "记了参数");
    T::has($log, "\"ok\":true", "记了结果");
    T::has($log, "\"ok\":false", "失败的也记");
    T::has($log, "\"peer\":\"127.0.0.1:", "记了来源 IP");
    T::has($log, "\"us\":", "记了耗时");
    T::has($log, "gm auth failed", "鉴权失败单独记一条 warn");

    // 失败原因进日志，但**不进响应**的细节（响应里只留一句）
    T::has($log, "下层炸了", "handler 的异常信息进日志");
}

function caseBrute2(): void
{
    echo "== [7] 爆破限流（独立服务）==" . PHP_EOL;
    @unlink(LOG_FILE);
    Logger::init("gm-test", LOG_FILE, Logger::DEBUG, false);
    Registry::clear();
    Scheduler::reset();
    $s = Scheduler::instance();
    $addr = $s->spawnOf(function (): Server {
        $srv = new Server("127.0.0.1", 19702, TOKEN, 2);
        $srv->setAuthFailLimit(2, 60000);
        return $srv;
    });

    // 用 19702 端口
    $sockAddr = 19702;
    $codes = [];
    for ($i = 0; $i < 4; $i++) {
        $raw = "POST /gm HTTP/1.1\r\nHost: x\r\nX-GM-Token: wrong\r\nContent-Length: 11\r\nConnection: close\r\n\r\n{\"cmd\":\"x\"}";
        $sock = @stream_socket_client("tcp://127.0.0.1:" . $sockAddr, $errno, $errstr, 1.0);
        if ($sock === false) {
            $codes[] = 0;
            continue;
        }
        @fwrite($sock, $raw);
        stream_set_blocking($sock, false);
        $buf = "";
        $deadline = Scheduler::nowMs() + 500;
        while (Scheduler::nowMs() < $deadline) {
            $s->tick();
            $chunk = @fread($sock, 8192);
            if ($chunk !== false && $chunk !== "") {
                $buf .= $chunk;
                continue;
            }
            if (feof($sock)) {
                break;
            }
            usleep(300);
        }
        @fclose($sock);
        $code = 0;
        if (preg_match('/^HTTP\/1\.1 (\d+)/', $buf, $m) === 1) {
            $code = (int) $m[1];
        }
        $codes[] = $code;
    }

    T::eq($codes[0] ?? 0, 401, "第 1 次错 token → 401");
    T::eq($codes[1] ?? 0, 401, "第 2 次错 token → 401");
    T::eq($codes[2] ?? 0, 429, "超过阈值后 → 429");
    T::eq($codes[3] ?? 0, 429, "继续 → 429");

    // 被限流之后，连正确的 token 也进不来（封的是 IP，不是那个请求）
    $r = $s->serviceOf($addr);
    if ($r instanceof Server) {
        $st = $r->stats();
        T::eq((int) $st["auth_fail"], 2, "只记了 2 次鉴权失败（后面直接被挡）");
        T::ok((int) $st["rejected"] >= 1, "拒绝计数 ≥1");
    }
}

/** 起一个真的服务，等着被 curl 打（验证 HTTP 框架不是只有自己写的客户端能认） */
function runServe(int $seconds): void
{
    @unlink(LOG_FILE);
    Logger::init("gm-serve", LOG_FILE, Logger::INFO, false);
    Registry::clear();
    Registry::on("ping", function (array $args): Result {
        return Result::ok("{\"pong\":true}");
    }, "联通性测试");
    Registry::on("player.ban", function (array $args): Result {
        $uid = isset($args["uid"]) ? (int) $args["uid"] : 0;
        $reason = isset($args["reason"]) ? (string) $args["reason"] : "";
        if ($uid <= 0) {
            return Result::fail("uid 必填");
        }
        if ($reason === "") {
            return Result::fail("reason 必填（封禁必须写理由）");
        }
        return Result::ok("{\"uid\":" . $uid . ",\"banned\":true,\"reason\":\"" . $reason . "\"}");
    }, "封禁玩家");

    Scheduler::reset();
    $s = Scheduler::instance();
    $s->spawnOf(function (): Server {
        return new Server("127.0.0.1", 19703, TOKEN, 3);
    });
    echo "ready at 19703" . PHP_EOL;

    $deadline = Scheduler::nowMs() + $seconds * 1000;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        usleep(500);
    }
}

/** http() 认死了主端口；这一节用的是另一个端口上的独立实例 */
function httpOn(int $port, Scheduler $s, string $body, array $headers): array
{
    $raw = "POST /gm HTTP/1.1\r\nHost: 127.0.0.1\r\n";
    foreach ($headers as $k => $v) {
        $raw .= $k . ": " . $v . "\r\n";
    }
    $raw .= "Content-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    $sock = @stream_socket_client("tcp://127.0.0.1:" . $port, $errno, $errstr, 1.0);
    if ($sock === false) {
        return [0, "connect failed: " . $errstr];
    }
    @fwrite($sock, $raw);
    stream_set_blocking($sock, false);
    $buf = "";
    $deadline = Scheduler::nowMs() + 500;
    while (Scheduler::nowMs() < $deadline) {
        $s->tick();
        $chunk = @fread($sock, 8192);
        if ($chunk !== false && $chunk !== "") {
            $buf .= $chunk;
            continue;
        }
        if (feof($sock)) {
            break;
        }
        usleep(300);
    }
    @fclose($sock);
    $status = 0;
    if (preg_match('/^HTTP\/1\.1 (\d+)/', $buf, $m) === 1) {
        $status = (int) $m[1];
    }
    return [$status, ""];
}

/**
 * 一人一个 token。此前 GM 通道只有一个共享 token，审计里的操作人靠请求头自报——
 * 想赖就能赖，也没法只让某一个人失效。
 */
function caseOperators(): void
{
    echo "== [5b] 一人一个 token ==" . PHP_EOL;
    Registry::clear();
    Registry::on("op.ping", function (array $args): Result {
        return Result::ok("{}");
    });
    $port = 19704;
    $tokA = "tok-a-0123456789abcdef";
    $tokB = "tok-b-0123456789abcdef";
    $req = "{\"cmd\":\"op.ping\"}";

    // 1) 共享 token 为空、又没有任何操作人 token：仍然拒绝启动
    $srv = new Server("127.0.0.1", $port, "", 2);
    $caught = "";
    try {
        $srv->onStart();
    } catch (\RuntimeException $e) {
        $caught = $e->getMessage();
    }
    T::ok($caught !== "", "没有共享 token 也没有操作人 token：拒绝启动");

    // 2) 配了操作人 token 就能起，而且不需要共享 token
    Scheduler::reset();
    @unlink(LOG_FILE);
    Logger::init("gm-test", LOG_FILE, Logger::DEBUG, false);
    $s = Scheduler::instance();
    // 先配好 token 再起：spawn 的时候会调 onStart，空 token 会在那里直接抛
    $gm = new Server("127.0.0.1", $port, "", 2);
    $gm->setOperators(["zhangsan" => $tokA, "lisi" => $tokB]);
    $s->spawnOf(function () use ($gm): Server {
        return $gm;
    });
    T::ok($gm->stats()["shared_token"] === 0, "没有共享 token");

    [$st] = httpOn($port, $s, $req, ["X-GM-Token" => $tokA, "X-GM-Operator" => "lisi"]);
    T::eq($st, 200, "zhangsan 的 token 能用");
    T::has(logText(), "\"op\":\"zhangsan\"", "审计里的操作人来自 token");
    T::ok(strpos(logText(), "\"op\":\"lisi\"") === false, "请求头自报的身份**不能**覆盖 token 的身份");

    @unlink(LOG_FILE);
    Logger::init("gm-test", LOG_FILE, Logger::DEBUG, false);
    [$st] = httpOn($port, $s, $req, ["X-GM-Token" => $tokB]);
    T::eq($st, 200, "lisi 的 token 能用");
    T::has(logText(), "\"op\":\"lisi\"", "记的是 lisi");

    [$st] = httpOn($port, $s, $req, ["X-GM-Token" => "tok-a-0123456789abcdee"]);
    T::eq($st, 401, "token 差一个字符 401");

    // 3) 摘掉一个人的 token
    $n = $gm->removeOperator("lisi");
    T::eq($n, 1, "摘掉了 lisi 的 token");
    [$st] = httpOn($port, $s, $req, ["X-GM-Token" => $tokB]);
    T::eq($st, 401, "lisi 的 token 立刻失效");
    [$st] = httpOn($port, $s, $req, ["X-GM-Token" => $tokA]);
    T::eq($st, 200, "zhangsan 不受影响");
    T::eq(count($gm->operators()), 1, "只剩一个操作人");
    T::eq($gm->stats()["operators"], 1, "stats 里能看到操作人 token 数");

    // 4) 共享 token 和操作人 token 并存
    $gm->setSharedToken("shared-0123456789abcdef");
    @unlink(LOG_FILE);
    Logger::init("gm-test", LOG_FILE, Logger::DEBUG, false);
    [$st] = httpOn($port, $s, $req, ["X-GM-Token" => "shared-0123456789abcdef", "X-GM-Operator" => "wangwu"]);
    T::eq($st, 200, "共享 token 仍然能用");
    T::has(logText(), "\"op\":\"wangwu\"", "共享 token 的操作人仍按请求头记");
    @unlink(LOG_FILE);
    Logger::init("gm-test", LOG_FILE, Logger::DEBUG, false);
    [$st] = httpOn($port, $s, $req, ["X-GM-Token" => $tokA, "X-GM-Operator" => "wangwu"]);
    T::has(logText(), "\"op\":\"zhangsan\"", "并存的规则不变：操作人 token 的身份优先");
}

function main(): void
{
    $args = $_SERVER["argv"] ?? [];
    if (isset($args[1]) && $args[1] === "serve") {
        runServe(isset($args[2]) ? (int) $args[2] : 10);
        return;
    }

    caseEmptyToken();

    [$s, $addr] = boot();
    caseBasics($s);
    caseDispatch($s);
    caseBadBody($s);
    caseAudit();
    caseOperators();

    $r = $s->serviceOf($addr);
    if ($r instanceof Server) {
        $st = $r->stats();
        printf(
            "  统计：requests=%d ok=%d fail=%d auth_fail=%d rejected=%d short_write=%d conns=%d\n",
            (int) $st["requests"],
            (int) $st["ok"],
            (int) $st["fail"],
            (int) $st["auth_fail"],
            (int) $st["rejected"],
            (int) $st["short_write"],
            (int) $st["conns"]
        );
        T::eq((int) $st["short_write"], 0, "没有短写");
    }

    caseBrute2();

    echo PHP_EOL . "结果: " . T::$pass . " 通过 / " . T::$fail . " 失败" . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
