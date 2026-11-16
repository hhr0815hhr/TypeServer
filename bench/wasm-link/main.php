<?php

// 验证「启用 wasm 包的工程能被 tsf build 构建」这条链路：
//   1. pkg 里的 C++ 参与编译
//   2. 工程的 ld-flags 被写进 project.yml（否则链不上 libtp_host）
//   3. 编出来的二进制能真的加载模块并调用
//
// 之前这三件事都做不到：tsf 只收 pkg/src、不生成 ld-flags，
// 所以手写 project.yml 能过，tsf build 就报链接错误。

use TSF\Wasm\Module;

function main(): void
{
    echo "bridge = " . Module::bridgeVersion() . PHP_EOL;

    $m = Module::load("mul", "/tmp/mulmod/mul50.cwasm");
    if ($m === null) {
        echo "load failed" . PHP_EOL;
        exit(1);
    }
    echo "loaded = " . $m->name() . PHP_EOL;
    echo "mul(6,7) = " . $m->call2("mul", 6, 7) . "  <- 应为 42" . PHP_EOL;
    echo "nope(1,1) = " . $m->call2("nope", 1, 1) . "  <- 应为负数" . PHP_EOL;
    $m->close();
}
