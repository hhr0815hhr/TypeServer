#!/bin/bash
# 验证套件的前置条件检查。
#
# 为什么要有这个脚本：`tsf verify` 失败时的报错来自最深的那一层
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

# 注意 wit-bindgen 通常装在 ~/.cargo/bin，默认不在 PATH 里（cargo 也不在）。
# 这里显式认它，否则会误报"没装"——这个误报我踩过一次。
missing=""
    if ! command -v "$t" > /dev/null 2>&1 && [ ! -x "$HOME/.cargo/bin/$t" ]; then
        missing="$missing $t"
    fi
done
if [ -z "$missing" ]; then
else
    echo "         wit-bindgen 一般在 ~/.cargo/bin，需要加进 PATH："
    echo "         export PATH=\"\$HOME/.cargo/bin:\$PATH\""
fi

# tp-precompile 是宿主的 Rust 构建产物，不可能入库，所以按三级查找：
# 显式 env > PATH > 仓库内 host 的构建目录。原来只认最后一条硬编码路径，
# 换机器或换目录就误报"未构建"。
PRE=${TSF_PRECOMPILE:-}
if [ -z "$PRE" ]; then
    PRE=$(command -v tp-precompile 2>/dev/null || true)
fi
if [ -z "$PRE" ]; then
    CANDIDATE="$(cd "$(dirname "$0")/.." && pwd)/spike/s2/host-capi/target/release/tp-precompile"
    [ -x "$CANDIDATE" ] && PRE=$CANDIDATE
fi
if [ -n "$PRE" ]; then
    ok "tp-precompile" "$PRE"
else
    echo "         先 cd spike/s2/host-capi && cargo build --release，或设 TSF_PRECOMPILE"
fi

echo
if [ "$fail" -ne 0 ]; then
    echo "前置条件不足，先补齐再跑 tsf verify"
    exit 1
fi
echo "前置条件齐备"
