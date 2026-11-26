#!/bin/bash
# 内存压力：在 OOM 之前落 dump。
#
# 为什么必须有：OOM 是 fatal error，catch 接不住、shutdown 钩子不执行、
# 进程直接 137。等它发生就什么都记不到了，只能提前动手。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/mem_pressure_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/mem_pressure_build.log）"
    tail -5 /tmp/mem_pressure_build.log
    exit 1
fi

if [ ! -x ./mem_pressure_test ]; then
    echo "FAIL 没找到产物 ./mem_pressure_test"
    exit 1
fi

RESULT=$(./mem_pressure_test 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL  ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
