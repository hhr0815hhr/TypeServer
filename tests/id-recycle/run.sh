#!/bin/bash
# 服务 id 回收：24 位地址空间用完一轮之后还能不能建服务。
#
# 为什么进回归：id 原来只增不收。16,777,215 次 spawn 之后这个进程永久不能再建服务，
# 到期的表现是"新房间开不出来"——进程不崩、日志里只有一行异常。这是"一局一房间服"
# 的进程寿命上限，而不是一个会报错的 bug，所以必须有断言盯住它。
#
# 两段：
#   unit  IdAllocator 在 12 个 id 的小空间里的行为（毫秒级）
#   full  真的绕一轮 24 位地址空间（1600 万次 spawn + kill，约 30–60 秒）
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
BIN=./id_recycle

if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/id_recycle_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/id_recycle_build.log）"
    tail -5 /tmp/id_recycle_build.log
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

FAIL=0
run_mode() {
    MODE="$1"
    echo "== $MODE =="
    ID_TEST="$MODE" "$BIN" > "/tmp/id_recycle_$MODE.log" 2>&1
    RC=$?
    grep '^  OK   \|^  FAIL ' "/tmp/id_recycle_$MODE.log"
    P=$(grep -c '^  OK   ' "/tmp/id_recycle_$MODE.log")
    F=$(grep -c '^  FAIL ' "/tmp/id_recycle_$MODE.log")
    echo "-- $MODE: passed=$P failed=$F rc=$RC"
    if [ "$RC" -ne 0 ] || [ "$F" -ne 0 ] || [ "$P" -eq 0 ]; then
        FAIL=$((FAIL + 1))
        grep -v '^  OK   \|^  FAIL ' "/tmp/id_recycle_$MODE.log" | tail -5 | sed 's/^/     /'
    fi
}

run_mode unit
run_mode full

echo
if [ "$FAIL" -eq 0 ]; then
    echo "全部通过"
    exit 0
fi
echo "$FAIL 段失败"
exit 1
