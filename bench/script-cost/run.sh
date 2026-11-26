#!/bin/bash
# 脚本层（内嵌 PHP 解释器）vs AOT 直调的成本标定。
#
# 不进 verify：这是基准不是回归，跑一次 1-2 秒但数字随机器浮动，
# 拿它当门禁会变成"换台机器就红"的假信号。
#
# 输出要贴回 docs/guide/12-hot-reload.md §五 和 docs/ROADMAP-NO-GAP.md 的 P0-3。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"

if ! php85 "$TSF" build . > /tmp/script_cost_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/script_cost_build.log）"
    tail -8 /tmp/script_cost_build.log
    exit 1
fi

if [ ! -f "$ROOT/ext/interp/modules/interp.so" ]; then
    echo "SKIP 需要先编译 ext/interp（cd ext/interp && phpize85 && ./configure --with-php-config=\$(which php-config85) && make）"
    exit 77
fi

mkdir -p /tmp/tsf-script-cost-conf
echo "extension=$ROOT/ext/interp/modules/interp.so" > /tmp/tsf-script-cost-conf/interp.ini

PHP_INI_SCAN_DIR="/tmp/tsf-script-cost-conf:/usr/local/php-8.5-zts/etc/conf.d" ./script_cost
