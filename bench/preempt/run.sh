#!/bin/bash
# 一段纯 CPU 循环会不会拖住其他所有协程的定时器。
#
# 判据是 probe 的最大醒来间隔：对照组约 5 ms，实验组若 ≈ CPU 循环时长，
# 就说明调度是协作式的——外部没有任何办法把一个 CPU 循环从进程里摘出去。
#
# 不做成 verify 套件：这是演示"能力边界"，不是回归断言。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/preempt_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/preempt_build.log）"
    tail -8 /tmp/preempt_build.log
    exit 1
fi

if [ ! -x ./preempt ]; then
    echo "FAIL 没找到产物 ./preempt"
    exit 1
fi

./preempt
