#!/bin/bash
# 验证套件的前置条件检查。
#
# 为什么要有这个脚本：`tsf verify` 失败时的报错来自最深的那一层，
# 看不出是环境缺东西还是代码真的坏了。把检查前置，能一眼分清这两类失败。
#
#   bash scripts/ci-preflight.sh
#
# CI 里由 `scripts/ci-setup.sh` 装完环境后调用；本地新人接手也先跑它。
set -u

PHP_BIN=${PHP_BIN:-php85}
TPC=${TPC:-$HOME/.tsf/toolchain/typephp/bin/tpc.php}
PHP_PREFIX=${PHP_PREFIX:-/usr/local/php-8.5-zts}

fail=0
ok()   { printf '[ OK ]  %-12s %s\n' "$1" "$2"; }
bad()  { printf '[FAIL]  %-12s %s\n' "$1" "$2"; fail=1; }
warn() { printf '[WARN]  %-12s %s\n' "$1" "$2"; }

# ---- PHP ----
if command -v "$PHP_BIN" > /dev/null 2>&1; then
    ver=$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null)
    zts=$("$PHP_BIN" -r 'echo PHP_ZTS ? "ZTS" : "NTS";' 2>/dev/null)
    if [ "$zts" = "ZTS" ]; then
        ok "$PHP_BIN" "$ver ($zts)"
    else
        # tpc 生成的代码要求运行时与编译器的 ZTS 状态一致，NTS 会在链接期报一堆莫名错误
        bad "$PHP_BIN" "$ver ($zts) —— 需要 ZTS"
    fi
else
    bad "$PHP_BIN" "不在 PATH（用 PHP_BIN=... 指定）"
fi

# ---- swoole ----
if "$PHP_BIN" -m 2>/dev/null | grep -qix swoole; then
    ok "swoole" "$("$PHP_BIN" -r 'echo phpversion("swoole") ?: "?";' 2>/dev/null)"
else
    bad "swoole" "扩展未加载"
fi

# ---- typephp 工具链 ----
if [ -f "$TPC" ]; then
    ok "tpc" "$TPC"
else
    bad "tpc" "找不到 $TPC（用 TPC=... 指定；它不在本仓库里）"
fi

if [ -f "$HOME/.tsf/toolchain/typephp/vendor/swoole/phpx/lib/libphpx.so" ]; then
    ok "phpx" "libphpx.so 就位"
else
    bad "phpx" "缺 libphpx.so（重跑 scripts/install-toolchain.sh）"
fi

# ---- 脚本热更的运行时 ----
interp=""
for f in "$PHP_PREFIX"/lib/php/extensions/*/interp.so "$(cd "$(dirname "$0")/.." && pwd)"/tsf/ext/interp/modules/interp.so; do
    [ -f "$f" ] && interp="$f" && break
done
if [ -n "$interp" ]; then
    ok "interp" "$interp"
else
    warn "interp" "没编译：script-test / rule-hot 会 SKIP（bash tsf/ext/interp/build.sh）"
fi

# ---- pdo_pgsql ----
if "$PHP_BIN" -r 'exit(in_array("pgsql", PDO::getAvailableDrivers(), true) ? 0 : 1);' >/dev/null 2>&1; then
    ok "pdo_pgsql" "已编入"
else
    warn "pdo_pgsql" "缺它 db-pg 套件会 SKIP"
fi

# ---- 以下三项缺了不是 SKIP 而是直接红，所以用 bad ----
# 2026-11-27 CI 第一次在 GitHub 上跑，redis-test / lock-test / config-check / config-test
# 就是红在这里：环境脚本没装 phpredis、没编 zip，也没有 openpyxl
if "$PHP_BIN" -r 'exit(class_exists("Redis") ? 0 : 1);' >/dev/null 2>&1; then
    ok "phpredis" "$("$PHP_BIN" -r 'echo phpversion("redis");' 2>/dev/null)"
else
    bad "phpredis" "pkg/redis 直接 new \\Redis()，缺了 redis-test / lock-test 必红（见 scripts/ci-setup.sh）"
fi

if "$PHP_BIN" -r 'exit(class_exists("ZipArchive") ? 0 : 1);' >/dev/null 2>&1; then
    ok "zip" "ZipArchive 可用"
else
    bad "zip" "config-check 套件用它搓 xlsx（configure 要带 --enable-zip）"
fi

if python3 -c "import openpyxl" >/dev/null 2>&1; then
    ok "openpyxl" "config-test 第 4、5 步要用"
else
    bad "openpyxl" "config-test 会 SKIP（pip install openpyxl）"
fi

# ---- docker（六个套件要真库）----
if command -v docker > /dev/null 2>&1 && docker info > /dev/null 2>&1; then
    ok "docker" "$(docker version --format '{{.Server.Version}}' 2>/dev/null)"
else
    warn "docker" "registry / mail / db-* / redis 六个套件会 SKIP"
fi

echo
if [ "$fail" -ne 0 ]; then
    echo "前置条件不足，先补齐再跑 tsf verify"
    exit 1
fi
echo "前置条件齐备"
