#!/bin/bash
# 构建 wasm 验收 fixture：两个模块各编一次 + 预编译成 cwasm。
#
# 为什么要有这一步：这个套件原来直接用 spike/s1 与 spike/e1 的产物，而 spike 是
# 本地验证现场、产物不入库 —— 干净克隆的仓库跑不了，CI 更跑不了。
set -eu -o pipefail
cd "$(dirname "$0")"

# WASI 工具链必须**整套**可见，少任何一类 tpc 都会在开头就失败：
#   ~/.local/bin             wasm32-wasip2-clang(++) 的转发 wrapper
#                            （LLVM 按 argv[0] 选驱动，符号链接不行）
#   ~/.local/wasi-sdk-34/bin llvm-ar / wasm-ld 等真工具
#   ~/.cargo/bin             wit-bindgen（cargo install 的产物，默认不在 PATH）
export PATH="$HOME/.local/bin:$HOME/.local/wasi-sdk-34/bin:$HOME/.cargo/bin:/usr/local/php-8.5-zts/bin:$PATH"

# 必须用 8.5：typephp 的 composer platform_check 会拦，PATH 里的 `php` 是 8.3。
# 这个坑很容易误判成"编译器坏了"——实际是跑 tpc 的解释器版本不对。
# tp-precompile 是宿主的 Rust 构建产物（预编译要带 fuel/epoch 插桩配置，
# 不能用 wasmtime CLI 代替），10MB 二进制不可能入库——它和 wasmtime 一样属于「环境」。
# 查找顺序：显式 env > PATH > 仓库内 host 的构建目录（本地开发方便）。
if [ -z "${TSF_PRECOMPILE:-}" ]; then
    HOST_BIN="$(cd ../../.. && pwd)/spike/s2/host-capi/target/release"
    if [ -x "$HOST_BIN/tp-precompile" ]; then
        export PATH="$HOST_BIN:$PATH"
    else
        echo "找不到 tp-precompile：先 cd spike/s2/host-capi && cargo build --release，" >&2
        echo "或用 TSF_PRECOMPILE 指向已有的二进制" >&2
        exit 1
    fi
fi

PHP_BIN=${PHP_BIN:-php85}
TSF=$(cd ../../.. && pwd)/tsf/bin/tsf

[ -x "$TSF" ] || { echo "找不到 tsf: $TSF" >&2; exit 1; }
command -v "$PHP_BIN" > /dev/null || { echo "找不到 $PHP_BIN（用 PHP_BIN= 指定）" >&2; exit 1; }

for v in v1 v2; do
    echo "== fixture $v =="
    if ! "$PHP_BIN" "$TSF" module build "$v" 2>&1 | tail -3; then
        echo "fixture $v 构建失败" >&2
        exit 1
    fi
done

echo
echo "== fixture 就绪 =="
ls -l v1/dist/*.cwasm v2/dist/*.cwasm | awk '{print $9, $5}'
