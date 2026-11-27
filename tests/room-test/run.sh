#!/bin/bash
# pkg/room 回归：生命周期、成员身份、帧循环、共享宿主、规模开销。
#
# 为什么单独有这个入口：这个套件原本只有 project.yml + main.php 和一个错误的
# 源码路径（指向不存在的 bench/room-test），既跑不起来也没进 `tsf verify`，
# 于是 `Room` 重构后它一路烂了半年没人发现。有 run.sh 才算真的能用。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/room_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/room_test_build.log）"
    tail -5 /tmp/room_test_build.log
    exit 1
fi

if [ ! -x ./room_test ]; then
    echo "FAIL 没找到产物 ./room_test"
    exit 1
fi

RESULT=$(./room_test 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
