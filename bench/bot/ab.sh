#!/usr/bin/env bash
# ZTS / NTS 交替对比：同一份代码、同一套压测参数，只换运行时。
# 交替（而不是先跑完 A 再跑完 B）是为了让机器负载漂移平均分摊到两边。
#
# 用法: ./ab.sh [轮数] [连接数] [速率Hz] [秒数]
set -u
ROUNDS=${1:-3}
CONNS=${2:-8500}
RATE=${3:-1}
SECS=${4:-10}

cd "$(dirname "$0")" || exit 1
for r in $(seq 1 "$ROUNDS"); do
  echo "--- 第 $r 轮 ---"

  OUT_Z=$(timeout 240 ./run.sh "$CONNS" "$RATE" "$SECS" 1 2>&1)
  Z_CPU=$(echo "$OUT_Z" | grep "服务端 CPU" | head -1)
  Z_MSG=$(echo "$OUT_Z" | grep "^sent=" | head -1)
  echo "ZTS  $Z_CPU"
  echo "     $Z_MSG"

  OUT_N=$(BIN=/data/TypeServer/demo/demo-echo-nts/demo_echo \
          CFG=/data/TypeServer/demo/demo-echo-nts/config/tsf.json \
          timeout 240 ./run.sh "$CONNS" "$RATE" "$SECS" 1 2>&1)
  N_CPU=$(echo "$OUT_N" | grep "服务端 CPU" | head -1)
  N_MSG=$(echo "$OUT_N" | grep "^sent=" | head -1)
  echo "NTS  $N_CPU"
  echo "     $N_MSG"
done
