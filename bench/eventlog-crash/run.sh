#!/bin/bash
# T4-04 验收：1,000 笔交易，写入进程被 kill -9 之后零丢失。
#
# 关键：kill -9 而不是 kill。优雅退出会走 close() → fclose，
# 那测的是"正常关闭"，不是"崩溃安全"。

set -u
cd "$(dirname "$0")"

LOG=/tmp/tsf_eventlog_crash.log
DONE=/tmp/tsf_eventlog_crash.done
rm -f "$LOG" "$DONE"

echo "=== 1. 起写入进程 ==="
./writer/writer > /tmp/elc_writer.log 2>&1 &
W=$!

# 等它写完 1000 条（writer 会创建 .done 文件）
for _ in $(seq 1 200); do
  if [ -f "$DONE" ]; then
    break
  fi
  sleep 0.05
done

if [ ! -f "$DONE" ]; then
  echo "写入进程没完成"
  cat /tmp/elc_writer.log
  kill -9 $W 2>/dev/null
  exit 1
fi

echo "写入完成，seq=$(cat $DONE)"
echo "杀之前的文件大小: $(stat -c%s "$LOG" 2>/dev/null) 字节"

echo "=== 2. kill -9（不给它 close 的机会）==="
kill -9 $W 2>/dev/null
wait $W 2>/dev/null
echo "已强杀"

echo "=== 3. 重新读日志 ==="
./reader/reader

echo "=== 4. 再跑一次（验证可重复）==="
rm -f "$LOG" "$DONE"
./writer/writer > /tmp/elc_writer2.log 2>&1 &
W2=$!
for _ in $(seq 1 200); do
  if [ -f "$DONE" ]; then
    break
  fi
  sleep 0.05
done
kill -9 $W2 2>/dev/null
wait $W2 2>/dev/null
./reader/reader

rm -f "$LOG" "$DONE"
echo "done"
