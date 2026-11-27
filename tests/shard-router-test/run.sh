#!/bin/bash
# 跨服路由语义：本地投递/codec 校验/过期失败/环重建后归属变化
#
# 与 shard-test 的分工：那个测哈希环的均衡度与迁移量（数学性质），
# 这个测路由决策的语义（该走本地还是远端、发不出去时怎么失败）。
# 原来只有 main.php 没有 run.sh，从没进过 verify。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/shard_router_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/shard_router_test_build.log）"
    tail -5 /tmp/shard_router_test_build.log
    exit 1
fi

if [ ! -x ./shard_router_test ]; then
    echo "FAIL 没找到产物 ./shard_router_test"
    exit 1
fi

RESULT=$(./shard_router_test 2>&1)
echo "$RESULT"

# 口径要跟着输出走：这个套件打的是 "  OK  " 而不是 "  PASS  "，
# 只数 PASS 会让 passed 永远是 0，输出看起来像"一项都没跑"
PASS=$(echo "$RESULT" | grep -cE '^  (PASS|OK)  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
