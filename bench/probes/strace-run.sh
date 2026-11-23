#!/bin/bash
# 统计 TSF 服务端在 pipeline 压测下的 syscall 构成。
#
# 用途：判断 6.5µs/帧里有多少是内核路径（sendto/recvfrom/epoll_wait），
# 有多少是用户态代码。如果 syscall 占大头 → 优化方向是合并写（writev）；
# 如果用户态占大头 → 优化方向是拆包/调用链。
#
#   bash run.sh <服务端 pid> [连接数] [秒数]
#
# 注意：strace 走 ptrace，会显著拖慢被测进程，吞吐数字不可用——
# 只看**占比**和**次数**，不看绝对值。
set -u
# pid 可以不给：默认取第一个 demo_echo 进程
PID=${1:-$(pgrep -x demo_echo | head -1)}
if [ -z "$PID" ]; then
    echo "没有找到 demo_echo 进程，先起服务" >&2
    exit 1
fi
CONNS=${2:-32}
SECS=${3:-4}

cd /data/TypeServer/bench || exit 1

strace -c -p "$PID" -o /tmp/tsf_strace.txt &
SPID=$!
sleep 1

echo "--- 压测 ${SECS}s / ${CONNS} 连接（pipeline64）---"
./load_pipe64 127.0.0.1 9001 "$CONNS" "$SECS" 2>&1 | tail -1

sleep 0.5
kill -INT "$SPID" 2>/dev/null
wait "$SPID" 2>/dev/null

echo "=== syscall 统计（注意：strace 会拖慢进程，只看占比）==="
cat /tmp/tsf_strace.txt
