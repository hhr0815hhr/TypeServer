#!/bin/bash
# 结算语义回归：一局一次、重放不重复、半截行、同 id 去重。
set -u
cd /data/TypeServer/bench/settle-test || exit 1
/data/TypeServer/tsf/bin/tsf build . 2>&1 | grep -E "Build successful|Fatal|error:" | head -4
./settle_test
