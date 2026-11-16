#!/usr/bin/env bash
# 跨服调用透明：两个进程各是一个"节点"，调用方按 uid 路由，一半落本地一半落远端。
set -u
cd "$(dirname "$0")" || exit 1
BIN=./shard_cross
N=${1:-200}

pkill -9 -x shard_cross 2>/dev/null
rm -rf /tmp/tsf_cross_reg
rm -f /tmp/tsf_cross1.sock /tmp/tsf_cross2.sock
mkdir -p /tmp/tsf_cross_reg
sleep 0.2

$BIN server 40 > /tmp/cross_srv.log 2>&1 &
for _ in $(seq 1 60); do
  [ -S /tmp/tsf_cross1.sock ] && break
  sleep 0.05
done

if [ ! -S /tmp/tsf_cross1.sock ]; then
  echo "服务端没起来"
  cat /tmp/cross_srv.log
  pkill -9 -x shard_cross 2>/dev/null
  exit 1
fi

echo "=== 客户端（节点 2）按 uid 发 $N 个请求 ==="
$BIN client "$N"
RC=$?

sleep 0.2
pkill -9 -x shard_cross 2>/dev/null
echo
echo "--- 服务端（节点 1）---"
cat /tmp/cross_srv.log
rm -rf /tmp/tsf_cross_reg
exit $RC
