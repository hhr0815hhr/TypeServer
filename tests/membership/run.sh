#!/bin/bash
# 成员联动：Cluster 保持连通的节点，跟着注册表里活着的节点走。
# 9423–9427，别的套件不用。
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
BIN=./membership

for p in 9423 9424 9425 9426 9427; do
    if ss -ltn 2>/dev/null | grep -q ":$p "; then
        echo "FAIL 端口 $p 已被占用（可能有别的套件在跑）"
        exit 1
    fi
done
if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/membership_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/membership_build.log）"
    tail -5 /tmp/membership_build.log
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

OUT=$(timeout 120 "$BIN" 2>&1)
RC=$?
echo "$OUT"
PASS=$(echo "$OUT" | grep -c '^  OK   ')
FAIL=$(echo "$OUT" | grep -c '^  FAIL ')
echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && [ "$PASS" -gt 0 ] && [ "$RC" -eq 0 ] && exit 0 || exit 1
