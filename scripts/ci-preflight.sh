#!/bin/bash
# 验证套件的前置条件检查。
#
# 为什么要有这个脚本：`tsf verify` 失败时的报错来自最深的那一层
# （"WASI toolchain check failed"、"function wasm_load is undefined"），
# 看不出是环境缺东西还是代码真的坏了。把检查前置，能一眼分清这两类失败。
#
# 本地也能跑：新人接手第一件事应该是 bash scripts/ci-preflight.sh。
set -u

PHP_BIN=${PHP_BIN:-php85}
TPC=${TPC:-/home/hhr/typephp-src/bin/tpc.php}

fail=0
ok()   { printf '[ OK ]  %-14s %s
' "$1" "$2"; }
bad()  { printf '[FAIL]  %-14s %s
' "$1" "$2"; fail=1; }
warn() { printf '[WARN]  %-14s %s
' "$1" "$2"; }

if command -v "$PHP_BIN" > /dev/null 2>&1; then
    ver=$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null)
    zts=$("$PHP_BIN" -r 'echo PHP_ZTS ? "ZTS" : "NTS";' 2>/dev/null)
    ok "$PHP_BIN" "$ver ($zts)"
    # tpc 生成的代码要求运行时与编译器的 ZTS 状态一致，NTS 会在链接期报一堆莫名错误
    if [ "$zts" != "ZTS" ]; then
        bad "PHP ZTS" "需要 ZTS 版本（tpc 用 ZTS 运行时）"
    fi
else
    bad "$PHP_BIN" "不在 PATH（用 PHP_BIN=... 指定）"
fi

if [ -f "$TPC" ]; then
    ok "tpc" "$TPC"
else
    bad "tpc" "找不到 $TPC（用 TPC=... 指定；它不在本仓库里）"
fi

if "$PHP_BIN" -m 2>/dev/null | grep -q swoole; then
    ok "swoole" "$("$PHP_BIN" -r 'echo phpversion("swoole") ?: "?";' 2>/dev/null)"
else
    bad "swoole" "扩展未加载"
fi

# wasm 套件需要额外三样；缺了只影响 wasm，不影响其它 8 个套件
# 注意 wit-bindgen 通常装在 ~/.cargo/bin，默认不在 PATH 里（cargo 也不在）。
# 这里显式认它，否则会误报"没装"——这个误报我踩过一次。
missing=""
for t in wasm32-wasip2-clang wasmtime wit-bindgen; do
    if ! command -v "$t" > /dev/null 2>&1 && [ ! -x "$HOME/.cargo/bin/$t" ]; then
        missing="$missing $t"
    fi
done
if [ -z "$missing" ]; then
    ok "wasm 工具链" "clang/wasmtime/wit-bindgen 就绪"
else
    warn "wasm 工具链" "缺:$missing —— wasm 套件会跳过，其余可跑"
    echo "         wit-bindgen 一般在 ~/.cargo/bin，需要加进 PATH："
    echo "         export PATH=\"\$HOME/.cargo/bin:\$PATH\""
fi

if [ -x /data/TypeServer/spike/s2/host-capi/target/release/tp-precompile ]; then
    ok "tp-precompile" "libtp_host 构建产物就位"
else
    warn "tp-precompile" "未构建（wasm 套件的预编译会失败）"
fi

echo
if [ "$fail" -ne 0 ]; then
    echo "前置条件不足，先补齐再跑 tsf verify"
    exit 1
fi
echo "前置条件齐备"
