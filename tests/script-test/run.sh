#!/bin/bash
# 脚本热更回归测试（pkg/script）。
#
# 六组：加载 / 对象共享 / 只读字段 / 越权写入 / 热更 / 异常隔离。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

# 产物名由工程名决定，这里不写死：build 之后找最新的可执行文件
OUT=""

PASS=0
FAIL=0

# 编译（增量，缓存命中会很快）
if ! "$TSF" build . > /tmp/script_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/script_test_build.log）"
    exit 1
fi

# 找 build 产物。排除源码与配置——从 Windows 同步过来的 .php 可能带执行位，
# 只按"可执行"判断会把它当成产物执行（现象是 bash 在解释 PHP 源码）
for f in *; do
    case "$f" in
        run.sh|*.php|*.json|*.yml|*.md) continue ;;
    esac
    if [ -x "$f" ]; then
        OUT="$f"
    fi
done

if [ -z "$OUT" ]; then
    echo "FAIL 没找到可执行产物"
    exit 1
fi

# 需要 interp 扩展；没装就跳过并说明
if [ ! -f "$ROOT/ext/interp/modules/interp.so" ]; then
    echo "SKIP 需要先编译 ext/interp（cd ext/interp && phpize85 && ./configure && make）"
    exit 0
fi

mkdir -p /tmp/tsf-test-conf
echo "extension=$ROOT/ext/interp/modules/interp.so" > /tmp/tsf-test-conf/interp.ini

RESULT=$(PHP_INI_SCAN_DIR="/tmp/tsf-test-conf:/usr/local/php-8.5-zts/etc/conf.d" "./$OUT" 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -c '^PASS ')
FAIL=$(echo "$RESULT" | grep -c '^FAIL ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
