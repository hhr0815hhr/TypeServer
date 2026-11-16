#!/usr/bin/env bash
# 构建交付包里的示例二进制（PHP AOT + wasm 宿主桥接）。
#
# 依赖：
#   - tpc（TypePHP 编译器）
#   - libtp_host.so（wasm 宿主 C ABI 库）
#
# 宿主库的取法（按优先级）：
#   1) 环境变量 TP_HOST_SO 指定
#   2) src/wasm-host/target/release/libtp_host.so（在包内用 cargo 现编）
#   3) 复用上级 spike 目录里已有的产物（省一次 5 分钟编译）
set -euo pipefail

KIT=$(cd "$(dirname "$0")" && pwd)
HOST_SO=${TP_HOST_SO:-}

if [ -z "$HOST_SO" ]; then
  if [ -f "$KIT/src/wasm-host/target/release/libtp_host.so" ]; then
    HOST_SO="$KIT/src/wasm-host/target/release/libtp_host.so"
  elif [ -f "$KIT/../spike/s2/host-capi/target/release/libtp_host.so" ]; then
    HOST_SO="$KIT/../spike/s2/host-capi/target/release/libtp_host.so"
    echo "复用已有宿主库：$HOST_SO"
  else
    echo "== 本地编译宿主库（约 5 分钟）=="
    (
      cd "$KIT/src/wasm-host"
      export PATH="$HOME/.cargo/bin:$PATH"
      export RUSTUP_HOME="$HOME/.rustup" CARGO_HOME="$HOME/.cargo"
      cargo build --release
    )
    HOST_SO="$KIT/src/wasm-host/target/release/libtp_host.so"
  fi
fi

[ -f "$HOST_SO" ] || { echo "找不到宿主库：$HOST_SO" >&2; exit 1; }

# 把库复制到包内 lib/，并用绝对路径写 rpath，保证可执行文件能独立加载
mkdir -p "$KIT/lib" "$KIT/build"
cp "$HOST_SO" "$KIT/lib/libtp_host.so"

# 生成 project.yml（绝对路径，避免相对路径在不同 cwd 下失效）
cat > "$KIT/build/project.yml" <<YML
name: kit-demo
version: 0.1.0
cxx-std: c++17
cxx-flags: |
  -O2
ld-flags: |
  -L$KIT/lib
  -Wl,--no-as-needed
  -ltp_host
  -Wl,-rpath,$KIT/lib
sources:
  - $KIT/src/wasm-host/php-src
  - $KIT/src/wasm-host/cpp-src
  - $KIT/src/wasm-host/main.php
YML

echo "== 编译示例 =="
export PATH="/usr/local/php-8.5-zts/bin:$PATH"
cd "$KIT/build"
php85 /home/hhr/typephp-src/bin/tpc.php project.yml

if [ -x "$KIT/build/kit_demo" ]; then
  echo "OK: $KIT/build/kit_demo"
else
  echo "构建产物未找到，请检查上面的编译输出" >&2
  exit 1
fi
