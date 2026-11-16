<?php

// S3 探针：在 S1 基础上增加"可长时间占用 CPU"的导出，用于验证
// wasmtime 的 epoch interruption / fuel 计量能否打断 PHP 侧的超长计算与死循环。
// 注意：#[WasmExport] 只能标注命名函数，编译器会把它们统一收进 runtime 资源的方法表。

#[\WasmExport]
function add(int $a, int $b): int
{
    return $a + $b;
}

#[\WasmExport]
function greet(string $name): string
{
    return "hello " . $name;
}

// 模拟一帧内的实体遍历：跨边界一次 + 内部计算
#[\WasmExport]
function simulateFrame(int $entityCount, int $seed): int
{
    $sum = 0;
    for ($i = 0; $i < $entityCount; $i++) {
        $sum += ($i * $seed) % 7;
    }
    return $sum;
}

// 超长计算：等价于"业务代码里写了一个没有边界保护的大循环"
#[\WasmExport]
function spin(int $iters): int
{
    $acc = 0;
    for ($i = 0; $i < $iters; $i++) {
        $acc = ($acc + $i * 7) % 1000003;
    }
    return $acc;
}

// 真死循环：退出条件与参数相关，起点 0、步长 2，传入奇数 target 时永远不相等。
// 不能写成 while(true){}——C++ 标准允许编译器删除"无副作用的非终止循环"，
// 实测 while ($i >= 0) 这种写法会被 clang 直接优化掉（瞬间返回）。
#[\WasmExport]
function forever(int $target): int
{
    // 循环体内做字符串拼接（每次都要分配内存），编译器无法把它当作
    // "无副作用的纯计算" 折叠掉——这一点很关键，见 SPIKE-S3 的踩坑记录。
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

// 构建新鲜度探针：常量返回值，用于确认 wasm 产物是否为最新编译结果
#[\WasmExport]
function freshProbe(int $x): int
{
    return 12345 + ($x - $x);
}
