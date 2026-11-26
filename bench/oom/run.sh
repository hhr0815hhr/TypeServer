#!/bin/bash
# OOM 时还有没有机会留诊断信息。
#
# 两组分开跑：OOM 会把进程带走，不能连着测。看的是"退出码 + 有没有输出"，
# 不是"跑通"。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/oom_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/oom_build.log）"
    tail -8 /tmp/oom_build.log
    exit 1
fi

if [ ! -x ./oom ]; then
    echo "FAIL 没找到产物 ./oom"
    exit 1
fi

echo "== [1] ini_set 对 memory_limit 生不生效 =="
MODE=limit ./oom
echo "  退出码 $?"

echo
echo "== [2] OOM 时 shutdown 钩子会不会执行 =="
rm -f /tmp/oom_last.txt
MODE=shutdown timeout 30 ./oom
CODE=$?
echo "  退出码 $CODE"
if [ -f /tmp/oom_last.txt ]; then
    echo "  → 落到了错误信息：$(cat /tmp/oom_last.txt)"
else
    echo "  → 没有留下任何错误信息"
fi
