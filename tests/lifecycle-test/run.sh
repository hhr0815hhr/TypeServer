#!/bin/bash
# 生命周期：批量销毁带定时器的服务后，时间轮里的死对象必须被清掉。
#
# 为什么必须进 verify：这个 bug 的所有对外信号都是"正常"的——
# 不报错、不崩溃、stats()["timers"] 显示 0、CPU 也正常，只有内存单调增长。
# 没有断言盯着，就只能等线上 OOM 才发现，而且查的时候完全看不出与定时器有关。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/lifecycle_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/lifecycle_test_build.log）"
    tail -5 /tmp/lifecycle_test_build.log
    exit 1
fi

if [ ! -x ./lifecycle_test ]; then
    echo "FAIL 没找到产物 ./lifecycle_test"
    exit 1
fi

RESULT=$(./lifecycle_test 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
