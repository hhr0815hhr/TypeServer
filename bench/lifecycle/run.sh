#!/bin/bash
# 生命周期稳定性：反复 spawn/kill 之后内存回不回基线、定时器有没有撤干净、
# 循环引用的 GC 要停多久。
#
# 为什么这组重要：框架推荐的用法就是"一房一 Service"，建房/销毁是常态。
# spawn/kill 每轮漏几十字节，跑一周就是 OOM，而现象是"越跑越慢"，
# 与任何一次具体改动都对不上号。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/lifecycle_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/lifecycle_build.log）"
    tail -8 /tmp/lifecycle_build.log
    exit 1
fi

if [ ! -x ./lifecycle ]; then
    echo "FAIL 没找到产物 ./lifecycle"
    exit 1
fi

echo "== 进程启动开销 =="
T0=$(date +%s%N)
./lifecycle > /tmp/lifecycle_out.txt 2>&1
T1=$(date +%s%N)
echo "  整个进程（启动 + 全部用例 + 退出）：$(( (T1 - T0) / 1000000 )) ms"
echo
cat /tmp/lifecycle_out.txt
