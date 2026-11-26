#!/bin/bash
# 时钟：cron 定时器必须按墙钟落点。
#
# 为什么必须有这条：cron 落点偏了的时候，stats() 里的 timers 数量完全正常，
# 定时器只是在"错误的时刻"触发——不报错、不崩溃，业务表现为
# "每天 3 点的活动没开，或者半夜开了"。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/clock_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/clock_test_build.log）"
    tail -5 /tmp/clock_test_build.log
    exit 1
fi

if [ ! -x ./clock_test ]; then
    echo "FAIL 没找到产物 ./clock_test"
    exit 1
fi

RESULT=$(./clock_test 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL  ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
