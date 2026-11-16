<?php

// wasm 热更验收 fixture（v1）。
//
// 这个模块**自包含在仓库里**：源码 + project.yml 都在版本控制内，
// 用 tsf module build 现编。之前这一套直接用 spike/s1 与 spike/e1 的产物，
// 而 spike 是本地验证现场、构建产物不入库——干净克隆的仓库跑不了这个套件。

#[\WasmExport]
function add(int $a, int $b): int
{
    return $a + $b;
}
