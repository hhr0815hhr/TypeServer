#!/bin/bash
# 调度时间片（setSlice）与 tick 总预算（setTickBudget）
#
# 为什么这组要有回归：这两个能力都是"减少长尾"的，出问题时**吞吐不变、只在压力下变慢**，
# 没有断言盯着就等于没有。三个关键语义必须钉住：
#   1. slice 只在消息边界生效（单条长消息拦不住，别指望它防死循环）
#   2. 被打断的服务消息一条不丢（排到队尾，不是丢弃）
#   3. 关掉开关后行为与原来逐字节一致（可逆，不能有残留状态）
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/slice_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/slice_test_build.log）"
    tail -5 /tmp/slice_test_build.log
    exit 1
fi

if [ ! -x ./slice_test ]; then
    echo "FAIL 没找到产物 ./slice_test"
    exit 1
fi

RESULT=$(./slice_test 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
