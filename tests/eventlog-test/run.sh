#!/bin/bash
# pkg/eventlog 回归：追加/重放/坏行修复/截断/多节点归并。
#
# 为什么补这个入口：这个套件原来只有 main.php 没有 run.sh，`tsf verify` 从没跑过。
# eventlog 丢一条事件不会崩、不会报错，只会在回放时少算数据——
# 正是最需要"每次回归都跑一遍"的那类模块。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/eventlog_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/eventlog_test_build.log）"
    tail -5 /tmp/eventlog_test_build.log
    exit 1
fi

if [ ! -x ./eventlog_test ]; then
    echo "FAIL 没找到产物 ./eventlog_test"
    exit 1
fi

RESULT=$(./eventlog_test 2>&1)
echo "$RESULT"

# 口径要跟着输出走：这个套件打的是 "  OK  " 而不是 "  PASS  "，
# 只数 PASS 会让 passed 永远是 0，输出看起来像"一项都没跑"
PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL  ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
