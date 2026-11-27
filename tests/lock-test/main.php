<?php

/**
 * pkg/redis 的分布式锁自测。
 *
 * 需要真 redis（`bash scripts/dev-redis.sh` 起一个 6379）。缺服务时 run.sh 会 SKIP。
 *
 * 重点不是"能加锁"，而是三条容易写错的地方：
 *   1. 同一个 key 第二次必须拿不到
 *   2. **别人的 token 必须释放/续期不了**（自己写 GET+DEL 最容易在这里出事故）
 *   3. 过期之后能再拿到，且此时 release 返回 false 而不是报错
 */

use TSF\Redis\Client;
use TSF\Redis\Lock;

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

function main(): void
{
    $c = new Client("127.0.0.1", 6379);
    if (!$c->connect()) {
        echo "  FAIL 连不上 redis 127.0.0.1:6379" . PHP_EOL;
        exit(1);
    }

    $lock = new Lock($c);
    $key = "lock:test:" . (string) getmypid();

    // 1. 拿到
    $t1 = $lock->acquire($key, 5000);
    T::ok($t1 !== "", "acquire 成功并返回 token");
    T::eq($lock->owner($key), $t1, "owner 就是刚拿到的 token");

    // 2. 二次拿不到
    T::eq($lock->acquire($key, 5000), "", "同一 key 第二次 acquire 失败");

    // 3. 不同 key 互不影响
    $other = $key . ":other";
    $t2 = $lock->acquire($other, 5000);
    T::ok($t2 !== "", "不同 key 可以同时持有");
    $lock->release($other, $t2);

    // 4. 别人的 token 释放不了
    T::eq($lock->release($key, "someone-else"), false, "别人的 token 释放失败");
    T::eq($lock->owner($key), $t1, "锁仍在原持有者手里");

    // 5. 别人的 token 续期不了
    T::eq($lock->extend($key, "someone-else", 5000), false, "别人的 token 续期失败");

    // 6. 自己续期、自己释放
    T::eq($lock->extend($key, $t1, 5000), true, "自己续期成功");
    T::eq($lock->release($key, $t1), true, "自己释放成功");
    T::eq($lock->owner($key), "", "释放后没人持有");

    // 7. 释放之后能再拿，且 token 换了（旧 token 不能复用来释放）
    $t3 = $lock->acquire($key, 5000);
    T::ok($t3 !== "", "释放后可再次获取");
    T::ok($t3 !== $t1, "新 token 与旧 token 不同");
    T::eq($lock->release($key, $t1), false, "拿旧 token 释放新锁失败");
    T::eq($lock->release($key, $t3), true, "用新 token 释放成功");

    // 8. 过期之后能再拿
    $t4 = $lock->acquire($key, 100);
    T::ok($t4 !== "", "短租期（100ms）获取成功");
    usleep(160000);
    $t5 = $lock->acquire($key, 5000);
    T::ok($t5 !== "", "过期后可再获取");
    if ($t5 !== "") {
        $lock->release($key, $t5);
    }

    // 9. 过期后 release 返回 false（不是报错，也不该把新锁删掉）
    $t6 = $lock->acquire($key, 100);
    T::ok($t6 !== "", "为过期用例重新拿锁");
    usleep(160000);
    $t7 = $lock->acquire($key, 5000);
    T::ok($t7 !== "", "别人拿到了这把已过期的锁");
    T::eq($lock->release($key, $t6), false, "过期者 release 返回 false");
    T::eq($lock->owner($key), $t7, "别人的锁没有被误删");
    $lock->release($key, $t7);

    // 10. 参数校验
    $threw = false;
    try {
        $lock->acquire($key, 0);
    } catch (\Throwable $e) {
        $threw = true;
    }
    T::ok($threw, "租期 <= 0 抛异常");

    echo PHP_EOL . "通过 " . T::$pass . " / 失败 " . T::$fail . PHP_EOL;
    exit(T::$fail === 0 ? 0 : 1);
}
