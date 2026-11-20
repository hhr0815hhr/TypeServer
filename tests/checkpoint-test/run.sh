#!/usr/bin/env bash
# 检查点回归：往返 / 截断安全 / 恢复等价 / 坏检查点被拒 / 恢复耗时。
#
# 纯本地文件，没有外部依赖，所以没有 SKIP 分支。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"
BIN=./checkpoint_test

if ! php85 "$TSF" build . > /tmp/checkpoint_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/checkpoint_test_build.log）"
    tail -5 /tmp/checkpoint_test_build.log
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
