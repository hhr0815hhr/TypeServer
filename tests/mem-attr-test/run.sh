#!/bin/bash
# 单服务内存归因（setMemGuard）
#
# 为什么要有回归：这个能力默认关闭，开着的时候也只写 error 日志。
# "没报错"和"没检测到"在日志里长得一样——没有断言就无法区分
# 「守卫正常工作」和「守卫根本没生效」。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/mem_attr_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/mem_attr_test_build.log）"
    tail -5 /tmp/mem_attr_test_build.log
    exit 1
fi

if [ ! -x ./mem_attr_test ]; then
    echo "FAIL 没找到产物 ./mem_attr_test"
    exit 1
fi

RESULT=$(./mem_attr_test 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
