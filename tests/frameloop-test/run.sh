#!/bin/bash
# pkg/frameloop 回归：delta 的三条边界（首帧 / 时间倒退 / 卡顿封顶）+ 统计。
#
# 为什么专测边界：delta 算错的后果都不是崩溃——首帧不封顶会"进门就飞出去"，
# 卡顿不封顶会"瞬移穿墙"，时间倒退不归零会"往回走"。三种都跑得起来，只是位置不对。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/frameloop_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/frameloop_test_build.log）"
    tail -5 /tmp/frameloop_test_build.log
    exit 1
fi

if [ ! -x ./frameloop_test ]; then
    echo "FAIL 没找到产物 ./frameloop_test"
    exit 1
fi

RESULT=$(./frameloop_test 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -c '^  PASS  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL  ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
