<?php

use Probe2\Frame;
use Probe2\JsonLike;

function bench(string $name, int $n, \Closure $fn): void
{
    for ($i = 0; $i < 5000; $i++) {
        $fn();
    }
    $t0 = hrtime(true);
    $acc = 0;
    for ($i = 0; $i < $n; $i++) {
        $acc += $fn();
    }
    $t1 = hrtime(true);
    echo "  " . str_pad($name, 34) . round(($t1 - $t0) / $n) . " ns" . PHP_EOL;
}

function main(): void
{
    $n = 300000;
    $p = new JsonLike();
    $obj = new Frame();
    $arr = ["uid" => 1001, "hp" => 88];
    $str = str_repeat("x", 64);

    echo "== interface 方法：string|array|object 参数 ==" . PHP_EOL;
    bench("pack(string)", $n, function () use ($p, $str) { return strlen($p->pack(1001, $str)); });
    bench("pack(array)", $n, function () use ($p, $arr) { return strlen($p->pack(1001, $arr)); });
    bench("pack(object)", $n, function () use ($p, $obj) { return strlen($p->pack(1001, $obj)); });

    echo PHP_EOL . "== 联合类型往返（接口分派）==" . PHP_EOL;
    bench("roundtrip(string)", $n, function () use ($p, $str) { return Probe2\roundtrip($p, $str); });
    bench("roundtrip(array)", $n, function () use ($p, $arr) { return Probe2\roundtrip($p, $arr); });

    echo PHP_EOL . "== 可空对象返回 ==" . PHP_EOL;
    bench("?Frame 返回", $n, function () { return Probe2\takeNullable(Probe2\makeFrame()); });
}
