#!/bin/bash
# 配置表校验器（导出期 Exporter）的负例测试。
#
# 解释执行，不编译产物：Exporter 依赖 ZipArchive 读 xlsx，那是开发机上的扩展，
# AOT 产物里不需要（配置表在开发期就转成 .bin 了）。
set -u
cd "$(dirname "$0")" || exit 1

PHP_BIN=${PHP_BIN:-php85}
if ! command -v "$PHP_BIN" > /dev/null 2>&1; then
    echo "SKIP 需要 $PHP_BIN（配置表的工具链只在开发机跑）"
    exit 77
fi

"$PHP_BIN" run.php
exit $?
