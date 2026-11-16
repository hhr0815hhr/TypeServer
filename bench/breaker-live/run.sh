#!/usr/bin/env bash
# 熔断实机验证：三个进程，节点 3 是"心跳正常但一个都不回"的半死节点。
#
# 为什么不用 kill -9：杀掉的节点会被注册表在 TTL 内摘除，测出来的就只是 TTL。
# 这里要测的是"注册表看不出问题"的那一半——所以节点 3 必须继续心跳。
set -u
cd "$(dirname "$0")" || exit 1
BIN=./breaker_live
PROBE=${1:-30}
HEALTHY=${2:-20}

pkill -9 -x breaker_live 2>/dev/null
rm -rf /tmp/tsf_bk_reg
rm -f /tmp/tsf_bk1.sock /tmp/tsf_bk2.sock /tmp/tsf_bk3.sock
mkdir -p /tmp/tsf_bk_reg
sleep 0.2

$BIN healthy 2 8 > /tmp/bk_node2.log 2>&1 &
P2=$!
$BIN sick 3 8 > /tmp/bk_node3.log 2>&1 &
P3=$!

for _ in $(seq 1 80); do
  [ -S /tmp/tsf_bk2.sock ] && [ -S /tmp/tsf_bk3.sock ] && break
  sleep 0.05
done
if [ ! -S /tmp/tsf_bk2.sock ] || [ ! -S /tmp/tsf_bk3.sock ]; then
  echo "节点没起来"
  cat /tmp/bk_node2.log /tmp/bk_node3.log
  pkill -9 -x breaker_live 2>/dev/null
  exit 1
fi

echo "=== 客户端（节点 1）先打半死的节点 3，再打健康的节点 2 ==="
$BIN client "$PROBE" "$HEALTHY"
RC=$?

sleep 0.3
# 等两个节点自然跑完，好让它们把"收了多少个请求"打出来——
# 那是"包到了、但一个都没回"的直接证据。顺手 kill 只是兜底
wait $P2 $P3 2>/dev/null
pkill -9 -x breaker_live 2>/dev/null
echo
echo "--- 节点 2（健康）---"
cat /tmp/bk_node2.log
echo "--- 节点 3（半死）---"
cat /tmp/bk_node3.log
rm -rf /tmp/tsf_bk_reg
exit $RC
