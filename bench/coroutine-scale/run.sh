#!/bin/bash
# 协程规模：几千个协程同时等 I/O 时的内存与延迟。
#
# 两个容易测错的地方：
#   1 协程栈是 C 层 mmap，**不在 PHP 堆里**，memory_get_usage 看不到——
#      必须读 /proc/self/status 的 VmRSS，否则会得出"协程不要钱"的错误结论
#   2 整个测试必须在 Coroutine\run 里跑。dispatchAsync 用 swoole_coroutine_create，
#      没有运行中的调度器时会静默失败（tick 返回 0，一条消息都没处理）
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/coroutine_scale_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/coroutine_scale_build.log）"
    tail -8 /tmp/coroutine_scale_build.log
    exit 1
fi

if [ ! -x ./coroutine_scale ]; then
    echo "FAIL 没找到产物 ./coroutine_scale"
    exit 1
fi

./coroutine_scale
