#!/usr/bin/env bash
# T6-03 回归：proto → PHP → AOT → 跑通，以及"写错了要说清楚"。
set -u
cd /data/TypeServer/bench/proto-test || exit 1
TSF=/data/TypeServer/tsf/bin/tsf

echo "=== 1. 生成 + 编译 + 运行 ==="
$TSF proto build . 2>&1 | head -3
$TSF build . 2>&1 | grep -E "Build successful|Fatal|error:" | head -3
./proto_test | tail -2

echo
echo "=== 2. 写错的地方要逐个报出来（且不生成任何文件）==="
rm -rf /tmp/proto_bad
cp -r . /tmp/proto_bad
rm -rf /tmp/proto_bad/gen /tmp/proto_bad/proto_test /tmp/proto_bad/proto/game.proto
cp proto-fixtures/bad.proto /tmp/proto_bad/proto/

OUT=$($TSF proto build /tmp/proto_bad 2>&1)
RC=$?
echo "$OUT" | head -12
echo

check() {
  if echo "$OUT" | grep -q "$1"; then
    echo "  OK   $2"
  else
    echo "  FAIL $2"
  fi
}

[ "$RC" -ne 0 ] && echo "  OK   退出码非 0" || echo "  FAIL 退出码应当是 1"
check "缺少协议号" "缺 @id 被指出"
check "不支持 map" "map 被拒绝"
check "不支持 oneof" "oneof 被拒绝"
check "类型 \[MyUnknown\] 不认识" "未知类型被指出"
check "字段号 1 重复" "字段号重复被指出"
check "不支持 sfixed32" "sfixed32 被明确拒绝（不是静默按无符号解）"
check "协议号 2003 被" "协议号冲突被指出"
check "不支持嵌套 message" "嵌套 message 被拒绝"

if [ -d /tmp/proto_bad/gen ]; then
  echo "  FAIL 校验失败却生成了 gen/ 目录"
else
  echo "  OK   校验失败没有留下任何生成物"
fi
rm -rf /tmp/proto_bad
