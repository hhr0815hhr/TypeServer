<?php

/**
 * 分派成本基准：AOT 下"具体类型方法 / 接口方法 / 闭包"各要多少 ns。
 *
 * 动机：把帧编解码从"注入闭包"改成"注入 FrameCodec 接口对象"后，
 * codec 的 A/B 比值从 +14.4% 掉到 +7.8%。要么是接口分派变贵了，
 * 要么是两次测量的机器状态不同。只有量出单次调用成本才能判断。
 */

interface Shape
{
    public function area(string $s): int;
}

final class Concrete implements Shape
{
    public function area(string $s): int
    {
        return strlen($s) + 1;
    }
}

final class Plain
{
    public function area(string $s): int
    {
        return strlen($s) + 1;
    }
}

/** 返回接口类型，强制调用点走接口分派 */
function makeShape(): Shape
{
    return new Concrete();
}

/** 返回具体类型 */
function makePlain(): Plain
{
    return new Plain();
}

function bench(string $name, int $n, \Closure $fn): void
{
    for ($i = 0; $i < 5000; $i++) {
        $fn();
    }
    $t0 = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $t1 = hrtime(true);
    echo "  " . str_pad($name, 34) . round(($t1 - $t0) / $n) . " ns" . PHP_EOL;
}

function main(): void
{
    $n = 500000;
    $body = str_repeat("x", 64);

    $concrete = new Concrete();
    $plain = new Plain();
    $iface = makeShape();
    $closure = function (string $s): int {
        return strlen($s) + 1;
    };

    // 属性里放接口对象：模拟 Transport 里的 $this->frame
    $holder = new class {
        public Shape $codec;

        public function __construct()
        {
            $this->codec = new Concrete();
        }

        public function viaProp(string $s): int
        {
            return $this->codec->area($s);
        }
    };

    bench("空循环（基线）", $n, function () {});
    bench("具体类型 ->area()", $n, function () use ($plain, $body) { $plain->area($body); });
    bench("接口类型 ->area()", $n, function () use ($iface, $body) { $iface->area($body); });
    bench("闭包调用", $n, function () use ($closure, $body) { $closure($body); });
    bench("属性(接口) ->area()", $n, function () use ($holder, $body) { $holder->viaProp($body); });
    bench("局部变量具体类型（对照）", $n, function () use ($concrete, $body) { $concrete->area($body); });
}
