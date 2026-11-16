<?php

// P1 stub：模块注册表 + 索引绑定 + 灰度切换 + 观测
// 约定：wasm_load 返回整数句柄（>=0），后续调用都用句柄，
//       调用路径上不做任何字符串转换（实测按名字查会多约 300 ns/次）。

function wasm_load(string $name, string $path): int
{
}

function wasm_add(int $h, int $a, int $b): int
{
}

function wasm_add_byname(int $h, int $a, int $b): int
{
}

function wasm_sim(int $h, int $entities, int $seed): int
{
}

function wasm_burn(int $h, int $iters): int
{
}

function wasm_swap(int $h, string $newPath): int
{
}

function wasm_stats(int $h, int $which): int
{
}

function wasm_count(): int
{
}

function wasm_set_timing(int $on): void
{
}

function wasm_close(int $h): void
{
}

function wasm_ping(int $x): int
{
}

function wasm_shutdown(): void
{
}
