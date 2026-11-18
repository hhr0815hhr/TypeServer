#!/bin/bash
# 打一个可分发的 typephp 工具链包（不含 PHP 本身）。
#
# ## 包里有什么
#
#   typephp/bin/                   tpc.php 等入口脚本
#   typephp/src/                   typephp 的 PHP 实现（编译器本体，213 个文件）
#   typephp/vendor/                **完整的 Composer 依赖**，但排除 dev 包
#
# 为什么必须带整个 vendor：tpc.php 第一行就 require vendor/autoload.php，
# 而它的 runtime 依赖里有几个大头不能省 ——
#   swoole/php-nano  46M  裁剪版 PHP 源码（TSRM/Zend），生成 stub 要用
#   swoole/phpx      13M  phpx 的头和 libphpx.so，链接产物要用
#   nikic/php-parser      解析 PHP 源码
# 排除的是纯 dev 工具（phpstan 85M / phpunit 7M / friendsofphp 5M），
# 它们不参与编译，却占掉一半体积。
#
# ## 包里没有什么（也打不进去）
#
#   - PHP 8.5 ZTS 本身（含 libphp.so）—— 几百 MB + 系统依赖，必须用户自己装
#   - clang / g++ / libgmp / libmprf      —— 系统包
#
# 所以在别的机器上解包后，tsf doctor 仍然可能报缺项 —— 这是设计如此：
# 能打包的打包，不能打包的明确告知，不做"看起来装好了但跑不起来"的假象。
#
# 用法：
#   scripts/package-toolchain.sh [输出目录，默认 dist/]

set -euo pipefail

SRC="${TYPEPHP_SRC:-$HOME/typephp-src}"
OUT="${1:-$(cd "$(dirname "$0")/.." && pwd)/dist}"

if [ ! -d "$SRC/bin" ] || [ ! -d "$SRC/src" ]; then
    echo "找不到 typephp 源码: $SRC" >&2
    echo "  用 TYPEPHP_SRC 指定，或先 clone 到 ~/typephp-src" >&2
    exit 1
fi

OS="$(uname -s | tr '[:upper:]' '[:lower:]')"
ARCH="$(uname -m)"

# 版本号：优先环境变量，其次 composer.json，最后 dev
VER="${TYPEPHP_VER:-}"
if [ -z "$VER" ] && [ -f "$SRC/composer.json" ]; then
    VER="$(grep -oE '"[0-9]+\.[0-9]+\.[0-9]+"' "$SRC/composer.json" 2>/dev/null | head -1 | tr -d '"' || true)"
fi
[ -z "$VER" ] && VER="dev"

NAME="tsf-toolchain-${OS}-${ARCH}-${VER}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

STAGE="$WORK/$NAME/typephp"
mkdir -p "$STAGE"

echo "== 1/5 拷贝 bin 与 src =="
cp -r "$SRC/bin" "$STAGE/"
cp -r "$SRC/src" "$STAGE/"
for f in bootstrap.php composer.json; do
    if [ -f "$SRC/$f" ]; then
        cp "$SRC/$f" "$STAGE/"
    fi
done

echo "== 2/5 拷贝 vendor（排除 dev 包）=="
if [ -d "$SRC/vendor" ]; then
    mkdir -p "$STAGE/vendor"
    skipped=""
    for sub in "$SRC"/vendor/*; do
        [ -e "$sub" ] || continue
        base="$(basename "$sub")"
        # 不排除任何包。试过剔除 phpstan（85M），结果 vendor/composer/autoload_real.php
        # 里还留着它的 bootstrap 引用（phpstan 在 composer 的 autoload_files 里注册了），
        # 加载时直接 fatal。要安全剔除必须在该目录跑 composer dump-autoload --no-dev，
        # 但那时目录已经删了，dump 又会报文件不存在 —— 循环依赖。
        # 85M 源码压缩后约 10M，不值得为它增加一条脆弱路径。
        cp -r "$sub" "$STAGE/vendor/"
    done
    echo "  跳过 dev 包：${skipped:-（无）}"
else
    echo "  警告：没有 vendor 目录，产物无法运行" >&2
fi

echo "== 3/5 清理 =="
rm -rf "$STAGE/tests" 2>/dev/null || true
find "$STAGE" -name '*.orig' -delete 2>/dev/null || true
rm -rf "$STAGE/vendor/swoole/php-nano/build" 2>/dev/null || true

echo "== 4/5 体积 =="
du -sh "$STAGE"

echo "== 5/5 打包 =="
mkdir -p "$OUT"
TARBALL="$OUT/${NAME}.tar.gz"
tar -czf "$TARBALL" -C "$WORK" "$NAME"

SIZE="$(du -h "$TARBALL" | cut -f1)"
echo
echo "产物: $TARBALL  ($SIZE)"
echo
echo "安装：解压后设两个环境变量"
echo "  export TPC=<解压目录>/$NAME/typephp/bin/tpc.php"
echo "  export TSF_PHP=php85"
echo "然后跑 tsf doctor 确认还缺什么。"
