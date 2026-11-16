<?php

/**
 * 微操作成本：找出限流/会话那 1.5 µs 到底花在哪。
 */

function bench(string $name, int $n, \Closure $fn): void
{
    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $t1 = hrtime(true);
    echo "  " . str_pad($name, 34) . round(($t1 - $t0) / $n) . " ns" . PHP_EOL;
}

function main(): void
{
    $n = 2000000;
    $arr = [];
    for ($i = 0; $i < 100; $i++) {
        $arr[$i] = $i * 1.5;
    }
    $fd = 7;
    $acc = 0.0;

    echo "== 原子操作成本 ==" . PHP_EOL;
    bench("microtime(true)", $n, function () { $t = microtime(true); });
    bench("hrtime(true)", $n, function () { $t = hrtime(true); });
    bench("isset(\$arr[\$fd])", $n, function () use ($arr, $fd) { $x = isset($arr[$fd]); });
    bench("读 \$arr[\$fd]", $n, function () use ($arr, $fd) { $x = $arr[$fd]; });
    bench("写 \$arr[\$fd] = 1.0", $n, function () use (&$arr, $fd) { $arr[$fd] = 1.0; });
    bench("读+写 \$arr[\$fd] += 1", $n, function () use (&$arr, $fd) { $arr[$fd] = $arr[$fd] + 1.0; });
    bench("time()", $n, function () { $t = time(); });

    echo PHP_EOL . "== 组合：一次典型的令牌桶更新 ==" . PHP_EOL;
    $tokens = 1000.0;
    $last = 1.0;
    $rate = 50.0;
    $burst = 100.0;
    bench("完整令牌桶（读2写2+算术）", $n, function () use (&$tokens, &$last, $rate, $burst) {
        $now = microtime(true);
        $elapsed = $now - $last;
        if ($elapsed > 0.0) {
            $tokens = $tokens + $elapsed * $rate;
            if ($tokens > $burst) {
                $tokens = $burst;
            }
            $last = $now;
        }
        if ($tokens < 1.0) {
            return false;
        }
        $tokens = $tokens - 1.0;
        return true;
    });

    echo PHP_EOL . "== 对象属性 vs 局部变量 ==" . PHP_EOL;
    $obj = new stdClass();
    $obj->lastActive = 0;
    bench("对象属性读", $n, function () use ($obj) { $x = $obj->lastActive; });
    bench("对象属性写", $n, function () use ($obj) { $obj->lastActive = 1; });
}
