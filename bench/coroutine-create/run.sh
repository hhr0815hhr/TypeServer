#!/bin/bash
# 协程创建成本拆解：11 µs/个 里有多少是我们自己的写法造成的。
#
# 四组逐层加码：新建空闭包 / 新建捕获闭包 / 复用闭包 / 复用+传参。
# 判据是 B 与 D 的差——那就是"闭包创建 + 捕获打包"的代价。
#
# 必须在 Coroutine\run 里跑：没有运行中的调度器时 swoole_coroutine_create
# 会静默失败，循环跑完但一个协程都没建（输出看不出异常）。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/coroutine_create_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/coroutine_create_build.log）"
    tail -8 /tmp/coroutine_create_build.log
    exit 1
fi

if [ ! -x ./coroutine_create ]; then
    echo "FAIL 没找到产物 ./coroutine_create"
    exit 1
fi

./coroutine_create
