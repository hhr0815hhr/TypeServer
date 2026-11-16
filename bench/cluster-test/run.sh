#!/usr/bin/env bash
# 跨进程往返测试：起两个进程（node1 服务端 / node2 客户端），走 UDS
set -u
cd /data/TypeServer/bench/cluster-test || exit 1
BIN=./cluster_test
N=${1:-2000}

pkill -9 -x cluster_test 2>/dev/null
rm -f /tmp/tsf_node1.sock /tmp/tsf_node2.sock
sleep 0.2

$BIN server > /tmp/cluster_srv.log 2>&1 &
for _ in $(seq 1 50); do
  [ -S /tmp/tsf_node1.sock ] && break
  sleep 0.05
done

$BIN client "$N" 2>&1
RC=$?

sleep 0.2
pkill -9 -x cluster_test 2>/dev/null
echo "--- 服务端 ---"
cat /tmp/cluster_srv.log
exit $RC
