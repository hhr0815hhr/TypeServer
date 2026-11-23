#!/bin/bash
# pkg/aoi 回归：坐标边界、跨格移动、3×3 与半径邻域（暴力法对拍）、规模。
#
# 为什么这个套件的重点不是"功能对不对"：AOI 算错的表现**不是崩溃**，
# 而是"视野里少一个人"——线上是"偶发看不到队友"，没有日志、没法复现。
# 所以用暴力法对拍（按定义算出应该看到谁，再和实现比），不依赖对实现的假设。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/aoi_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/aoi_test_build.log）"
    tail -5 /tmp/aoi_test_build.log
    exit 1
fi

if [ ! -x ./aoi_test ]; then
    echo "FAIL 没找到产物 ./aoi_test"
    exit 1
fi

RESULT=$(./aoi_test 2>&1)
echo "$RESULT"

PASS=$(echo "$RESULT" | grep -c '^  PASS  ')
FAIL=$(echo "$RESULT" | grep -c '^  FAIL  ')

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
