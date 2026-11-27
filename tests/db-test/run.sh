#!/bin/bash
# pkg/db：事务/嵌套/批量/Repo（sqlite 内存库）
#
# 为什么补这个入口：这个套件原来只有 main.php 没有 run.sh，`tsf verify` 从没跑过它。
# 更糟的是 main.php 结尾没有 exit(非零)，所以连 `tsf test` 也一直判 PASS——
# db 包（唯一的数据一致性模块）长期在"看起来有测试"的状态下裸奔。
半年没人发现。有 run.sh 才算真的能用。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/db_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/db_test_build.log）"
    tail -5 /tmp/db_test_build.log
    exit 1
fi

if [ ! -x ./db_test ]; then
    echo "FAIL 没找到产物 ./db_test"
    exit 1
fi

RESULT=$(./db_test 2>&1)
echo "$RESULT"

# 口径要跟着输出走：这个套件打的是 "  OK  " 而不是 "  PASS  "，
# 只数 PASS 会让 passed 永远是 0，输出看起来像"一项都没跑"
PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
