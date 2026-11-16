#!/usr/bin/env bash
# 集群压测：3 节点，跨服混合流量，中途把节点 3 冻住（心跳正常、收包不回）。
#
# 关键点：**不杀进程**。杀掉的话注册表 3 秒就摘了、环重建成 2 节点、
# uid 全部重分布——那测的是缩容，不是故障。这里要测的是
# "节点还在、但不干活"这种情况（热更新卡住、GC 卡死、下游全超时）。
set -u
cd /data/TypeServer/bench/cluster-bench || exit 1
BIN=./cluster_bench
PER=${1:-6000}
WIN=${2:-32}
# 默认用 §18.8 修复后的配置。要复现 §18.2~18.7 里"没有上限"的数字，
# 第 3 个参数传 64（或更大）即可
CAP=${3:-12}

pkill -9 -x cluster_bench 2>/dev/null
rm -rf /tmp/tsf_cb_reg
rm -f /tmp/tsf_cb1.sock /tmp/tsf_cb2.sock /tmp/tsf_cb3.sock /tmp/tsf_cb_freeze
mkdir -p /tmp/tsf_cb_reg
sleep 0.2

$BIN node 2 45 > /tmp/cb_node2.log 2>&1 &
P2=$!
$BIN node 3 45 > /tmp/cb_node3.log 2>&1 &
P3=$!

for _ in $(seq 1 80); do
  [ -S /tmp/tsf_cb2.sock ] && [ -S /tmp/tsf_cb3.sock ] && break
  sleep 0.05
done
if [ ! -S /tmp/tsf_cb2.sock ] || [ ! -S /tmp/tsf_cb3.sock ]; then
  echo "节点没起来"
  cat /tmp/cb_node2.log /tmp/cb_node3.log
  pkill -9 -x cluster_bench 2>/dev/null
  exit 1
fi

$BIN drive "$PER" "$WIN" "$CAP"
RC=$?

sleep 0.3
pkill -9 -x cluster_bench 2>/dev/null
echo
echo "--- 节点 2 ---"
cat /tmp/cb_node2.log
echo "--- 节点 3 ---"
cat /tmp/cb_node3.log
rm -rf /tmp/tsf_cb_reg
rm -f /tmp/tsf_cb_freeze
exit $RC
