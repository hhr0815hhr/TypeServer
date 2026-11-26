#!/bin/bash
# 日志轮转 + 具名通道。
#
# 为什么进回归：修之前 Logger 是无上限的单文件追加——一个 info 级循环日志就能在几小时内
# 写满磁盘，之后所有日志静默消失（`@file_put_contents` 吃掉错误）。GM 审计和运行日志
# 混在同一个文件里，事后按操作人找一条记录要在几百万行心跳里翻。
#
# 三段：
#   unit    LogFile 的轮转规则
#   boot    经 Bootstrap 走配置 + gm 独立通道
#   writer  两个进程并发写同一个文件：轮转不能转出空层、不能出坏行
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
BIN=./log_rotate
FAIL=0

if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/log_rotate_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/log_rotate_build.log）"
    tail -5 /tmp/log_rotate_build.log
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

report() {   # report <日志文件> <段名>
    grep '^  OK   \|^  FAIL ' "$1"
    P=$(grep -c '^  OK   ' "$1")
    F=$(grep -c '^  FAIL ' "$1")
    echo "-- $2: passed=$P failed=$F"
    if [ "$F" -ne 0 ] || [ "$P" -eq 0 ]; then
        FAIL=$((FAIL + 1))
        grep -v '^  OK   \|^  FAIL ' "$1" | tail -6 | sed 's/^/     /'
    fi
}

echo "== unit =="
LOGT=unit "$BIN" > /tmp/logt_unit.log 2>&1
RC=$?
report /tmp/logt_unit.log unit
[ "$RC" -eq 0 ] || { echo "     unit 段异常退出 rc=$RC"; FAIL=$((FAIL + 1)); }

echo
echo "== boot =="
# 清掉上一轮留下的轮转层，否则"转出了 .1"在没轮转的版本上也会成立
rm -rf var/log
LOGT=boot "$BIN" > /tmp/logt_boot.log 2>&1
RC=$?
report /tmp/logt_boot.log boot
[ "$RC" -eq 0 ] || { echo "     boot 段异常退出 rc=$RC"; FAIL=$((FAIL + 1)); }

echo
echo "== 并发写入 =="
SHARED=/tmp/tsf_logt_shared.log
rm -f "$SHARED" "$SHARED".* 
LOGT=writer LOGT_FILE="$SHARED" "$BIN" > /tmp/logt_w1.log 2>&1 &
W1=$!
LOGT=writer LOGT_FILE="$SHARED" "$BIN" > /tmp/logt_w2.log 2>&1 &
W2=$!
wait "$W1"; R1=$?
wait "$W2"; R2=$?
if [ "$R1" -ne 0 ] || [ "$R2" -ne 0 ]; then
    echo "  FAIL  writer 进程异常退出 rc=$R1/$R2"
    tail -3 /tmp/logt_w1.log /tmp/logt_w2.log | sed 's/^/        /'
    FAIL=$((FAIL + 1))
fi
LOGT=check LOGT_FILE="$SHARED" "$BIN" > /tmp/logt_check.log 2>&1
RC=$?
report /tmp/logt_check.log check
[ "$RC" -eq 0 ] || { echo "     check 段异常退出 rc=$RC"; FAIL=$((FAIL + 1)); }

echo
if [ "$FAIL" -eq 0 ]; then
    echo "全部通过"
    exit 0
fi
echo "$FAIL 段失败"
exit 1
