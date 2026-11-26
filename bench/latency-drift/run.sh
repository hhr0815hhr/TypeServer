#!/bin/bash
# 长跑延迟漂移：调度间隔的 p99 会不会随时间变差。
#
# 内存碎片、定时器堆垃圾、哈希表历史峰值都只在长时间运行后显形。
# 这类问题在几十秒的压测里看不到，线上表现为"跑了一天才开始卡、重启就好"。
#
# 跑一轮 30 秒。不进 verify：时间太长，而且判据是"趋势持平"而不是硬阈值。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/latency_drift_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/latency_drift_build.log）"
    tail -8 /tmp/latency_drift_build.log
    exit 1
fi

if [ ! -x ./latency_drift ]; then
    echo "FAIL 没找到产物 ./latency_drift"
    exit 1
fi

./latency_drift
