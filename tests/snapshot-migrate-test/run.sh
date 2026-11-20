#!/usr/bin/env bash
# 状态版本迁移链回归：缺 _v / 分组阈值 / 拒绝降级 / 幂等 / Restorer 集成。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"
BIN=./snapshot_migrate_test

if ! php85 "$TSF" build . > /tmp/snapshot_migrate_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/snapshot_migrate_build.log）"
    tail -5 /tmp/snapshot_migrate_build.log
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
