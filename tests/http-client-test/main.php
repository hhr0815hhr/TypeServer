<?php

/**
 * 出站 HTTP 客户端的自测。
 *
 * 目标服务由 run.sh 用 `php -S` 起在 127.0.0.1:9633（见同目录 server.php）。
 *
 * 重点测的不是"能发出请求"，而是三个容易出事的地方：
 *   1. **环境代理**：机器上有 http_proxy 时必须仍然直连（否则内网请求被带出去）
 *   2. **超时**：慢接口要按 timeout 返回，而不是把 worker 拖到天荒地老
 *   3. **失败不抛异常**：连不上时 status=0 + error 有值，调用方能稳定地写降级
 */

use TSF\Http\Client;

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

function main(): void
{
    $base = "http://127.0.0.1:9633";
    $c = new Client(500, 3000);

    // 1. 正常 GET
    $r = $c->get($base . "/ok");
    T::ok($r->status === 200 && $r->body === "hello" && $r->ok(), "GET /ok → 200 + body",
        $r->describe() . " body=" . $r->body);

    // 2. 404：有响应但 failed
    $r = $c->get($base . "/missing");
    T::ok($r->status === 404 && $r->failed() && !$r->ok(), "GET 不存在的路径 → 404 且 failed()",
        $r->describe());

    // 3. POST + 自定义头：服务端要能收到 method / 头 / body
    $r = $c->post($base . "/echo", "a=1&b=2", ["X-Trace" => "abc123"]);
    T::ok($r->status === 200 && strpos($r->body, "POST") !== false, "POST 方法送达", $r->describe());
    T::ok(strpos($r->body, "abc123") !== false, "自定义头送达", "body=" . $r->body);
    T::ok(strpos($r->body, "a=1&amp;b=2") !== false || strpos($r->body, "a=1&b=2") !== false,
        "请求体送达", "body=" . $r->body);

    // 4. 环境代理：设一个坏代理后，本机请求仍应直连成功
    //    第一版探针就是被这个坑到：code=502、1.8 秒，看起来像 curl 不能用
    putenv("http_proxy=http://127.0.0.1:1");
    putenv("HTTP_PROXY=http://127.0.0.1:1");
    $r = $c->get($base . "/ok");
    T::ok($r->status === 200 && $r->body === "hello", "环境变量里有坏代理时仍然直连", $r->describe());
    putenv("http_proxy");
    putenv("HTTP_PROXY");

    // 5. 连不上：status=0 + error，不抛异常
    $dead = new Client(200, 500);
    $r = $dead->get("http://127.0.0.1:9634/ok");
    T::ok($r->status === 0 && $r->error !== "" && !$r->ok(), "端口不通 → status=0 且 error 有值",
        $r->describe());

    // 6. 超时：慢接口按 timeout 返回
    $fast = new Client(200, 300);
    $r = $fast->get($base . "/slow");
    T::ok($r->status === 0 && $r->error !== "", "慢接口按 timeout 返回", $r->describe());
    T::ok($r->elapsedMs < 1500, "超时没有等到接口返回", "elapsedMs=" . (string) $r->elapsedMs);

    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
