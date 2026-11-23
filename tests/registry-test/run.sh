#!/usr/bin/env bash
# Redis 注册表回归：注册可见 / 字段往返 / 跨连接一致 / 心跳续命 / TTL 过期 / 注销收敛。
#
# 需要 127.0.0.1:6379 的 Redis。没有的话打 SKIP 并 **exit 77** —— 环境没装不该判红，
# 但也别假装测过：exit 0 会让 verify 显示成绿色 PASS，"一条都没测"变成"通过了"。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"
BIN=./registry_test

if ! php85 "$TSF" build . > /tmp/registry_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/registry_test_build.log）"
    tail -5 /tmp/registry_test_build.log
    exit 1
fi

if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

OUT=$("$BIN" 2>&1)
echo "$OUT"

# 77 是 verify 约定的 SKIP。原来这里 exit 0，于是"Redis 不可用、租约一条没测"
# 在 verify 里显示成 PASS —— 整个套件的保护其实是空的，而且是绿色的。
if echo "$OUT" | grep -q "^SKIP "; then
    echo "SKIP 依赖 Redis 不可用，租约与注册表未验证"
    exit 77
fi

FAIL=$(echo "$OUT" | grep -c "  FAIL  ")
PASS=$(echo "$OUT" | grep -c "  PASS  ")

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
