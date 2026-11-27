#!/bin/bash
# 协程化 Scheduler：让出与恢复语义
#
# 为什么补这个入口：这个套件原来只有 main.php 没有 run.sh，`tsf verify` 从没跑过它。
# 有测试文件但从不执行，等于没有。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/coroutine_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/coroutine_test_build.log）"
    tail -5 /tmp/coroutine_test_build.log
    exit 1
fi

if [ ! -x ./coroutine_test ]; then
    echo "FAIL 没找到产物 ./coroutine_test"
    exit 1
fi

RESULT=$(./coroutine_test 2>&1)
echo "$RESULT"

# 这个套件不打逐条断言，只给一行判定："判定: OK ..." 或 "判定: FAIL ..."。
# 以前这里也去数 PASS/FAIL 行，恒为 passed=0 failed=0；而退出码只看数出来的 FAIL，
# 所以**判定是 FAIL 的时候退出码也是 0**。2026-11-27 verify 开始把 passed=0 failed=0
# 判成失败，这才暴露出来。现在看判定行本身。
if echo "$RESULT" | grep -qE '^判定: OK '; then
  echo "判定: PASS（协程模式下慢调用不阻塞别的协程）"
  exit 0
fi
echo "判定: FAIL（没有「判定: OK」：要么真有尖峰，要么产物没输出判定）"
exit 1
