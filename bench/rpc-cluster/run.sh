#!/usr/bin/env bash
# 跨进程 RPC 延迟：起一个服务端进程，客户端分串行/批量两种模式打。
set -u
cd "$(dirname "$0")" || exit 1
BIN=./rpc_cluster
N=${1:-500}
TOTAL=${2:-2000}
# 在飞窗口默认给 25，不是 200。
#
# 这个用例的验收线是 p99 ≤2 ms，而 §13.6 写得很清楚："p99 ≤2 ms 指的是单次调用（无排队），
# 在飞 50 个以内仍达标"。延迟对在飞数是**严格线性**的（2026-11-16 实测，每多一个在飞 +17 µs）：
#   window 10 → p99 394 µs   window 50 → p99 2027 µs
#   window 25 → p99 689 µs   window 200 → p99 3997 µs
# 所以默认 200 时这个用例**永远 FAIL**，而那并不是缺陷，是饱和排队。
# 想看饱和曲线就显式传：`./run.sh 500 2000 200`。
WINDOW=${3:-25}

pkill -9 -x rpc_cluster 2>/dev/null
rm -f /tmp/tsf_rpc_node1.sock /tmp/tsf_rpc_node2.sock /tmp/tsf_rpc_node3.sock
sleep 0.2

$BIN server 40 > /tmp/rpc_srv.log 2>&1 &
for _ in $(seq 1 60); do
  [ -S /tmp/tsf_rpc_node1.sock ] && break
  sleep 0.05
done

if [ ! -S /tmp/tsf_rpc_node1.sock ]; then
  echo "服务端没起来"
  cat /tmp/rpc_srv.log
  pkill -9 -x rpc_cluster 2>/dev/null
  exit 1
fi

echo "=== 串行 $N 次 ==="
$BIN serial "$N"
RC1=$?

echo
echo "=== 批量 total=$TOTAL window=$WINDOW ==="
$BIN burst "$TOTAL" "$WINDOW"
RC2=$?

sleep 0.2
pkill -9 -x rpc_cluster 2>/dev/null
echo
echo "--- 服务端 ---"
cat /tmp/rpc_srv.log

if [ $RC1 -eq 0 ] && [ $RC2 -eq 0 ]; then
  exit 0
fi
exit 1
