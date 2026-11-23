#!/bin/bash
# 场景形态容量验证：万人世界 / 大量副本 / 混合。
#
# 测的是**纯逻辑**（移动 + 换格 + 视野差集），不含广播编码和网络发送。
# 判据：20Hz 下一帧预算 50ms。
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)

if [ ! -x ./scene_scale ]; then
    "$ROOT/tsf/bin/tsf" build . > /dev/null 2>&1 || { echo "构建失败"; exit 1; }
fi

./scene_scale
