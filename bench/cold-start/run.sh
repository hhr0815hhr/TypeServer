#!/bin/bash
# 冷启动：AOT 产物从 exec 到退出要多久。决定滚动重启能多激进。
#
# 跑 20 次取中位数，不取最小值：最小值是缓存全热的最佳情况，
# 用它做容量规划会偏乐观（线上滚动重启时页缓存、CPU 频率都未必是热的）。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/cold_start_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/cold_start_build.log）"
    tail -8 /tmp/cold_start_build.log
    exit 1
fi

if [ ! -x ./cold_start ]; then
    echo "FAIL 没找到产物 ./cold_start"
    exit 1
fi

echo "== 冷启动（空 main，20 次取中位）=="
TIMES=""
for i in $(seq 1 20); do
    T0=$(date +%s%N)
    ./cold_start
    T1=$(date +%s%N)
    TIMES="$TIMES $(( (T1 - T0) / 1000 ))"
done

# 排序取中位（bash 里没有 sort -n 的数组版，塞进临时文件）
echo "$TIMES" | tr ' ' '\n' | grep -v '^$' | sort -n > /tmp/cold_start_times.txt
MEDIAN=$(sed -n '10p' /tmp/cold_start_times.txt)
MIN=$(head -1 /tmp/cold_start_times.txt)
MAX=$(tail -1 /tmp/cold_start_times.txt)

echo "  中位 ${MEDIAN} µs   最小 ${MIN} µs   最大 ${MAX} µs"
echo "  → 含动态链接 libphp.so + 模块初始化 + 运行时建立 + 退出清理"
