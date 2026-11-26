#!/bin/bash
# Service 载体成本：空服务的内存/创建开销、定时器开销、投递扇出。
#
# 这个基准回答的是选型问题——"房卡房该一房一 Service 还是多房共享宿主"，
# 取决于**载体**的固定成本，与房间逻辑无关。数据被
# skills/tsf-dev/references/scene-carrier.md 引用，改了要同步那份。
#
# 不进 verify：基准不是回归，数字随机器浮动（实测同一台机器上跑三次，
# 创建耗时能在 2.1-2.5 µs 之间晃），拿它当门禁只会变成假红。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/service_cost_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/service_cost_build.log）"
    tail -8 /tmp/service_cost_build.log
    exit 1
fi

if [ ! -x ./service_cost ]; then
    echo "FAIL 没找到产物 ./service_cost"
    exit 1
fi

./service_cost
