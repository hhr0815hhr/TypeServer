#!/usr/bin/env bash
# Redis 注册表回归：注册可见 / 字段往返 / 跨连接一致 / 心跳续命 / TTL 过期 / 注销收敛。
#
# 需要 127.0.0.1:6379 的 Redis。没有的话打 SKIP 并返回 0 —— 环境没装不该判红，
# 但也别假装测过（SKIP 字样会出现在 verify 的输出里）。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"
BIN=./registry_test

if ! php85 "$TSF" build . > /tmp/registry_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/registry_test_build.log）"
    tail -5 /tmp/registry_test_build.log
    exit 1
fi

if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

OUT=$("$BIN" 2>&1)
echo "$OUT"

case "$OUT" in
    *SKIP*) exit 0 ;;
esac

FAIL=$(echo "$OUT" | grep -c "  FAIL  ")
PASS=$(echo "$OUT" | grep -c "  PASS  ")

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
