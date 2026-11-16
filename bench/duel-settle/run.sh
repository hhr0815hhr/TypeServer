#!/bin/bash
# 结算接线回归：对局结束 → 一帧 → 金币到账。
set -u
cd /data/TypeServer/bench/duel-settle || exit 1
/data/TypeServer/tsf/bin/tsf build . 2>&1 | grep -E "Build successful|Fatal|error:" | head -4
./duel_settle
