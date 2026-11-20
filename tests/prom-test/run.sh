#!/usr/bin/env bash
# Prometheus 导出回归：格式 / 名字合法性 / TYPE 唯一 / 路由直连 / 空表。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"
BIN=./prom_test

if ! php85 "$TSF" build . > /tmp/prom_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/prom_test_build.log）"
    tail -5 /tmp/prom_test_build.log
    exit 1
fi

if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

OUT=$("$BIN" 2>&1)
echo "$OUT"

FAIL=$(echo "$OUT" | grep -c "  FAIL  ")
PASS=$(echo "$OUT" | grep -c "  PASS  ")

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
