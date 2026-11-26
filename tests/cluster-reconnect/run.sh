#!/bin/bash
# 集群断线重连：对端重启 / 一直起不来 / 两端同时互拨，分别是什么表现。
#
# 为什么进回归：修之前 dropLink() 之后永久失联——对端重启一次，跨节点消息从此全部进 drop，
# 而本节点还活着。另外 connect() 是同步阻塞的（最长 2 秒），挡住的是 1ms 一轮的调度循环。
# 9416–9419 四个端口，别的套件不用。
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
BIN=./cluster_reconnect

for p in 9416 9417 9418 9419; do
    if ss -ltn 2>/dev/null | grep -q ":$p "; then
        echo "FAIL 端口 $p 已被占用（可能有别的套件在跑）"
        exit 1
    fi
done
if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/cluster_reconnect_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/cluster_reconnect_build.log）"
    tail -5 /tmp/cluster_reconnect_build.log
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
