<?php

/**
 * pkg/redis 的自测。
 *
 * 跑之前需要先起一个 redis（或 tests/redis-test/mock_redis.py）：
 *   python3 tests/redis-test/mock_redis.py 6379 &
 *   ./redis_test
 *
 * 这个测试故意只依赖 RESP 协议的**行为**，不依赖真 redis 的实现细节：
 * 命令编码、五种回复类型、nil、数组、错误、重连。
 */

use TSF\Redis\Client;
use TSF\Redis\Resp;

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

    public static function eq(string|int|float|bool|null $got, string|int|float|bool|null $want, string $label): void
    {
        self::ok($got === $want, $label, "got=" . var_export($got, true) . " want=" . var_export($want, true));
    }
}

/** 1. 编码：命令要拼成 RESP2 的数组格式 */
function caseEncode(): void
{
    echo "== [1] 命令编码 ==" . PHP_EOL;
    $r = Resp::encode(["SET", "k", "v"]);
    T::eq($r, "*3\r\n$3\r\nSET\r\n$1\r\nk\r\n$1\r\nv\r\n", "SET k v");
    $r2 = Resp::encode(["PING"]);
    T::eq($r2, "*1\r\n$4\r\nPING\r\n", "PING");
    // 中文按字节算长度，不是字符数
    $r3 = Resp::encode(["SET", "名字", "甲"]);
    T::ok(strpos($r3, "$6\r\n") !== false, "中文字段按字节计长");
}

/** 2. 基本命令（依赖 mock 或真 redis） */
function caseBasic(string $host, int $port, string $pass): void
{
    echo "== [2] 基本命令 ==" . PHP_EOL;
    $c = new Client($host, $port, $pass, 0, 3.0);
    if (!$c->connect()) {
        echo "  跳过：连不上 " . $host . ":" . $port . PHP_EOL;
        return;
    }
    T::ok($c->ping(), "PING → PONG");

    T::ok($c->set("tsf:t:k", "hello"), "SET 返回 OK");
    T::eq($c->get("tsf:t:k"), "hello", "GET 读回来");
    T::eq($c->get("tsf:t:nope"), null, "GET 不存在的 key → null");

    $c->del("tsf:t:k", "tsf:t:k2");
    T::ok(!$c->exists("tsf:t:k"), "DEL 之后不存在");

    T::ok($c->set("tsf:t:ttl", "v", 60), "SETEX 带过期");
    $c->del("tsf:t:ttl");

    $c->incrBy("tsf:t:n", 5);
    T::eq($c->incrBy("tsf:t:n", 3), 8, "INCRBY 累加");
    $c->del("tsf:t:n");

    $c->close();
}

/** 3. 哈希 */
function caseHash(string $host, int $port, string $pass): void
{
    echo "== [3] 哈希 ==" . PHP_EOL;
    $c = new Client($host, $port, $pass, 0, 3.0);
    if (!$c->connect()) {
        echo "  跳过" . PHP_EOL;
        return;
    }
    $c->del("tsf:t:h");
    $c->hSet("tsf:t:h", "name", "甲");
    $c->hSet("tsf:t:h", "lv", "7");
    T::eq($c->hGet("tsf:t:h", "name"), "甲", "HGET");
    $all = $c->hGetAll("tsf:t:h");
    T::eq(count($all), 2, "HGETALL 两项");
    T::eq($all["lv"] ?? "", "7", "HGETALL 内容");
    T::eq($c->hIncrBy("tsf:t:h", "lv", 3), 10, "HINCRBY");
    $c->del("tsf:t:h");
    $c->close();
}

/** 4. 有序集合（排行榜） */
function caseZset(string $host, int $port, string $pass): void
{
    echo "== [4] 有序集合 ==" . PHP_EOL;
    $c = new Client($host, $port, $pass, 0, 3.0);
    if (!$c->connect()) {
        echo "  跳过" . PHP_EOL;
        return;
    }
    $c->del("tsf:t:rank");
    $c->zAdd("tsf:t:rank", 100.0, "甲");
    $c->zAdd("tsf:t:rank", 300.0, "乙");
    $c->zAdd("tsf:t:rank", 200.0, "丙");

    T::eq($c->zCard("tsf:t:rank"), 3, "ZCARD");
    $top = $c->zRevRange("tsf:t:rank", 0, 2);
    T::eq(implode(",", $top), "乙,丙,甲", "ZREVRANGE 从高到低");

    $with = $c->zRevRangeWithScores("tsf:t:rank", 0, 0);
    T::ok(count($with) === 1 && ($with[0][0] ?? "") === "乙", "带分数取第一名");

    T::eq($c->zScore("tsf:t:rank", "丙"), 200.0, "ZSCORE");
    T::eq($c->zRevRank("tsf:t:rank", "甲"), 2, "ZREVRANK 第三名");
    T::eq($c->zIncrBy("tsf:t:rank", 250.0, "甲"), 350.0, "ZINCRBY 反超");

    $c->del("tsf:t:rank");
    $c->close();
}

/** 5. 重连：关掉连接后下一次命令要能自己连回来 */
function caseReconnect(string $host, int $port, string $pass): void
{
    echo "== [5] 重连 ==" . PHP_EOL;
    $c = new Client($host, $port, $pass, 0, 3.0);
    if (!$c->connect()) {
        echo "  跳过" . PHP_EOL;
        return;
    }
    T::ok($c->ping(), "先通一次");
    // 模拟"连接被踢"：直接关掉底层 socket，客户端不知道
    $c->close();
    T::ok($c->ping(), "close 之后 ping 能自动重连");
    $c->close();
}

function main(): void
{
    $host = getenv("REDIS_HOST") ?: "127.0.0.1";
    $port = (int) (getenv("REDIS_PORT") ?: "6379");
    $pass = getenv("REDIS_AUTH") ?: "";

    echo "目标 redis: " . $host . ":" . $port
        . ($pass === "" ? "（无密码）" : "（有密码）") . PHP_EOL . PHP_EOL;

    caseEncode();
    caseBasic($host, $port, $pass);
    caseHash($host, $port, $pass);
    caseZset($host, $port, $pass);
    caseReconnect($host, $port, $pass);

    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
    if (T::$fail > 0) {
        exit(1);
    }
}
