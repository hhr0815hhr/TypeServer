#!/usr/bin/env bash
# RPC 回归：正常/失败/超时/迟到响应/并发/守卫/自定义 codec/协议不匹配/载荷版本协商。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"
BIN=./rpc_test

if ! php85 "$TSF" build . > /tmp/rpc_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/rpc_test_build.log）"
    tail -5 /tmp/rpc_test_build.log
    exit 1
fi

if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

OUT=$("$BIN" 2>&1)
echo "$OUT"

# 这个套件的输出是 "  OK   " / "  FAIL "（历史格式），两种都认
FAIL=$(echo "$OUT" | grep -c -E "^  (FAIL|FAIL )")
PASS=$(echo "$OUT" | grep -c -E "^  (OK|PASS)")

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
