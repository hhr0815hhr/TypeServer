#!/usr/bin/env bash
# 状态交接（handoff）回归：正常交接 / 幂等重发 / 目标拒绝 / 超时回滚 / 迟到 ACK / 并发拒绝。
#
# 不需要外部依赖：两个节点都在本进程内用 TCP 回环建链，所以没有 SKIP 分支。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"
BIN=./handoff_test

if ! php85 "$TSF" build . > /tmp/handoff_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/handoff_test_build.log）"
    tail -5 /tmp/handoff_test_build.log
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
