#!/bin/bash
# 服务生命周期：启动/停机/crash dump
#
# 为什么补这个入口：这个套件原来只有 main.php 没有 run.sh，`tsf verify` 从没跑过它。
# 有测试文件但从不执行，等于没有。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/service_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/service_test_build.log）"
    tail -5 /tmp/service_test_build.log
    exit 1
fi

if [ ! -x ./service_test ]; then
    echo "FAIL 没找到产物 ./service_test"
    exit 1
fi

RESULT=$(./service_test 2>&1)
echo "$RESULT"

# 口径要跟着输出走：这个套件打的是 "  OK  " 而不是 "  PASS  "，
# 只数 PASS 会让 passed 永远是 0，输出看起来像"一项都没跑"
PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
