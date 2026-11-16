#!/bin/bash
# wasm 热更链路验收：灰度分流 → 确定性 → 全量 → 回滚。
#
# 判定不看"有没有报错"，看**返回值**：v1 的 add 是 a+b（3），v2 是 a+b+1000（1003）。
# 只看 HTTP/退出码这一层，测不出"换了个版本但行为没变"这种问题。
set -u
cd "$(dirname "$0")" || exit 1

TSF=../../tsf/bin/tsf
BIN=./wasm_link

# 先构建 fixture（源码在 fixtures/v1、fixtures/v2，用 tsf module build 现编）
echo "== 构建 wasm fixture =="
if ! bash fixtures/build.sh > /tmp/wl_fixture.log 2>&1; then
    echo "FAIL fixture 构建"
    tail -6 /tmp/wl_fixture.log
    exit 1
fi

if ! $TSF build . > /tmp/wl_build.log 2>&1; then
    echo "FAIL 构建"
    tail -4 /tmp/wl_build.log
    exit 1
fi

OUT=$("$BIN" 2>&1)
echo "$OUT" | grep -vE '^\[enum\]'

fail() { echo "FAIL $1"; exit 1; }

echo "$OUT" | grep -q 'add(1,2) = 3  <- 3' || fail "基线：v1 应返回 3"
echo "$OUT" | grep -q 'v1=10 v2=10' || fail "灰度：50% 应精确分成 10/10"
echo "$OUT" | grep -q 'key=7 twice: 1003 / 1003' || fail "确定性：同一 key 两次结果必须相同"
echo "$OUT" | grep -q 'add(1,2) = 1003' || fail "全量：swap 后应返回 1003"
echo "$OUT" | grep -q 'ratio=0' || fail "切换后灰度实例应被清理"
echo "$OUT" | grep -q 'add(1,2) = 3  <- 3（已回滚）' || fail "回滚：行为必须恢复成 3"

echo "PASS 6 项"
