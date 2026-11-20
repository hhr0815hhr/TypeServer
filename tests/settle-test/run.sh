#!/bin/bash
# 结算语义回归：一局一次、重放不重复、半截行、同 id 去重。
set -u
cd "$(dirname "$0")" || exit 1
# 编译失败必须直接退出：继续跑 ./settle_test 会用上一次的产物，
# 结果是"改了代码却是绿的"——比报错更难发现。
if ! /data/TypeServer/tsf/bin/tsf build . > /tmp/settle_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/settle_test_build.log）"
    tail -5 /tmp/settle_test_build.log
    exit 1
fi
./settle_test
