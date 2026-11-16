#!/usr/bin/env bash
# 把 spike 里的现行实现镜像到 implementation-kit/src/。
# 原则：spike 是源，kit 是交付副本；改了 spike 后重跑本脚本同步。
set -eu
KIT=/data/TypeServer/implementation-kit
SRC=/data/TypeServer/spike

mkdir -p "$KIT/src/wasm-host/src/bin" \
         "$KIT/src/wasm-host/php-src" \
         "$KIT/src/wasm-host/cpp-src" \
         "$KIT/src/wasm-module" \
         "$KIT/src/supervisor"

# 1) Rust 宿主库 + 预编译工具
cp "$SRC/s2/host-capi/Cargo.toml"          "$KIT/src/wasm-host/Cargo.toml"
cp "$SRC/s2/host-capi/src/lib.rs"          "$KIT/src/wasm-host/src/lib.rs"
cp "$SRC/s2/host-capi/src/bin/precompile.rs" "$KIT/src/wasm-host/src/bin/precompile.rs"

# 2) PHP 侧接入：stub + C++ 桥接 + 工程配置 + 发版脚本
cp "$SRC/e1/project.yml"                   "$KIT/src/wasm-host/project.yml"
cp "$SRC/e1/php-src/wasm.stub.php"         "$KIT/src/wasm-host/php-src/wasm.stub.php"
cp "$SRC/e1/cpp-src/wasm_bridge.cc"        "$KIT/src/wasm-host/cpp-src/wasm_bridge.cc"
cp "$SRC/e1/main.php"                      "$KIT/src/wasm-host/main.php"
cp "$SRC/e1/precompile.sh"                 "$KIT/src/wasm-host/precompile.sh"

# 3) wasm 模块示例（v1 / v2）
cp "$SRC/s1/project.yml"                   "$KIT/src/wasm-module/project-v1.yml"
cp "$SRC/s1/lib.php"                       "$KIT/src/wasm-module/lib-v1.php"
cp "$SRC/e1/module_v2/project.yml"         "$KIT/src/wasm-module/project-v2.yml"
cp "$SRC/e1/module_v2/lib.php"             "$KIT/src/wasm-module/lib-v2.php"

# 4) 房间监督器
cp "$SRC/e4/project.yml"                   "$KIT/src/supervisor/project.yml"
cp "$SRC/e4/main.php"                      "$KIT/src/supervisor/main.php"

echo "synced to $KIT/src"
find "$KIT/src" -type f | sort
