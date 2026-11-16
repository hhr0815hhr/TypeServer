<?php

// P1 灰度演示用的 v2 模块：接口与 v1 完全一致，仅行为不同。
// 这样可以在同一进程里同时加载 v1 / v2，验证"多模块共存 + 原子切换"。

#[\WasmExport]
function add(int $a, int $b): int
{
    // v1 是 a + b，这里故意 +1000，便于从返回值区分版本
    return $a + $b + 1000;
}

#[\WasmExport]
function greet(string $name): string
{
    return "v2: hello " . $name;
}

#[\WasmExport]
function simulateFrame(int $entityCount, int $seed): int
{
    $sum = 0;
    for ($i = 0; $i < $entityCount; $i++) {
        $sum += ($i * $seed) % 7;
    }
    return $sum + 1000;
}

#[\WasmExport]
function spin(int $iters): int
{
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $acc = ($acc + $i * 7) % 1000003;
    }
    return $acc;
}

#[\WasmExport]
function forever(int $target): int
{
    $s = "x";
    $i = 0;
    while ($i != $target) {
        $s .= "y";
        $i = ($i + 2) % 1000000007;
        if ($i > 1000000) {
            $s = "x";
        }
    }
    return 0;
}
