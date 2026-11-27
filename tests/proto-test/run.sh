#!/usr/bin/env bash
# T6-03 回归：proto → PHP → AOT → 跑通，以及"写错了要说清楚"。
#
# 2026-11-27 补严：此前 tsf 的路径写死成 /data/TypeServer，且第 1 步的输出都经过
# `| head` / `| tail`，退出码被管道吞掉——CI 第一次在 GitHub 上跑时这里"通过"了，
# 实际上一条命令都没执行成。现在路径跟着仓库走，第 1 步每一步都检查。
set -u
cd "$(dirname "$0")" || exit 1
TSF="$(cd ../.. && pwd)/tsf/bin/tsf"
FAILS=0

bad() {
  echo "  FAIL $*"
  FAILS=$((FAILS + 1))
}

echo "=== 1. 生成 + 编译 + 运行 ==="
if ! $TSF proto build . > /tmp/proto_gen.log 2>&1; then
  bad "tsf proto build 失败（日志 /tmp/proto_gen.log）"
  tail -5 /tmp/proto_gen.log
  echo "FAIL 第 1 步就失败了，后面没有意义"
  exit 1
fi
head -3 /tmp/proto_gen.log
if ! $TSF build . > /tmp/proto_build.log 2>&1; then
  bad "tsf build 失败（日志 /tmp/proto_build.log）"
  tail -5 /tmp/proto_build.log
  echo "FAIL 第 1 步就失败了，后面没有意义"
  exit 1
fi
grep -E "Build successful|Fatal|error:" /tmp/proto_build.log | head -3
if [ ! -x ./proto_test ]; then
  bad "没有找到产物 ./proto_test"
  exit 1
fi
OUT1=$(./proto_test 2>&1)
echo "$OUT1" | tail -2
if echo "$OUT1" | grep -qE "^\s*FAIL\b|failed=[1-9]"; then
  bad "proto_test 的运行期断言有失败"
fi

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
    FAILS=$((FAILS + 1))
  fi
}

if [ "$RC" -ne 0 ]; then
  echo "  OK   退出码非 0"
else
  bad "退出码应当是 1"
fi
check "缺少协议号" "缺 @id 被指出"
check "不支持 map" "map 被拒绝"
check "不支持 oneof" "oneof 被拒绝"
check "类型 \[MyUnknown\] 不认识" "未知类型被指出"
check "字段号 1 重复" "字段号重复被指出"
check "不支持 sfixed32" "sfixed32 被明确拒绝（不是静默按无符号解）"
check "协议号 2003 被" "协议号冲突被指出"
check "不支持嵌套 message" "嵌套 message 被拒绝"

if [ -d /tmp/proto_bad/gen ]; then
  bad "校验失败却生成了 gen/ 目录"
else
  echo "  OK   校验失败没有留下任何生成物"
fi
rm -rf /tmp/proto_bad

# 以前这个脚本以最后一条命令（rm）的退出码结束，判定行里有多少 FAIL 都不影响结果
if [ "$FAILS" -eq 0 ]; then
  echo "PASS proto 工具链"
  exit 0
fi
echo "FAIL proto 工具链：$FAILS 项失败"
exit 1
