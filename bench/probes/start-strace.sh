#!/bin/bash
# 用 strace 包裹启动服务端，统计 pipeline 压测下的 syscall 构成。
#
# 为什么不用 `strace -p`：WSL 的 kernel.yama.ptrace_scope 默认限制非 root attach，
# 会报 Operation not permitted。**父进程包裹不受这个限制**。
#
#   bash start-strace.sh [连接数] [秒数]
#
# 注意：strace 有 ptrace 开销，吞吐绝对值不可信——只看**占比**和**次数**。
set -u
CONNS=${1:-32}
SECS=${2:-4}
DEMO=/data/TypeServer/demo/demo-echo
BENCH=/data/TypeServer/bench

pkill -x demo_echo 2>/dev/null
sleep 0.3

cd "$DEMO" || exit 1
strace -c -f -o /tmp/tsf_strace.txt env TSF_ENV=bench-nogw ./demo_echo > /tmp/de_st.log 2>&1 &
SPID=$!
sleep 2.5

cd "$BENCH" || exit 1
echo "--- 压测 ${SECS}s / ${CONNS} 连接（pipeline64，strace 在跑，数字偏慢）---"
./load_pipe64 127.0.0.1 9001 "$CONNS" "$SECS" 2>&1 | tail -1

pkill -x demo_echo 2>/dev/null
sleep 1
kill -INT "$SPID" 2>/dev/null
wait "$SPID" 2>/dev/null

echo "=== syscall 统计 ==="
cat /tmp/tsf_strace.txt
