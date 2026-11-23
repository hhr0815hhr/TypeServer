#!/bin/bash
# pkg/schedule：cron 判定 + 注册表 + 与核心对拍
#
# 为什么补这个入口：这个套件原来只有 main.php 没有 run.sh，于是**从没进过 verify**——
# 有测试文件但从不执行，等于没有。这次改核心 Cron（支持 6 字段）时才发现它不在
# 回归里，等于在裸奔。
烂了半年没人发现。有 run.sh 才算真的能用。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/schedule_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/schedule_test_build.log）"
    tail -5 /tmp/schedule_test_build.log
    exit 1
fi

if [ ! -x ./schedule_test ]; then
    echo "FAIL 没找到产物 ./schedule_test"
    exit 1
fi

RESULT=$(./schedule_test 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -c '^  PASS  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL  ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
