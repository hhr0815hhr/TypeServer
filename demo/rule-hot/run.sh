#!/usr/bin/env bash
# 规则热更端到端演示。
#
#   ./run.sh             编译 + 跑完整演示，结束自动停服务
#   ./run.sh --no-build  跳过编译（已编过）
#   ./run.sh --keep      跑完保留服务（手动点 GM 用）
#
# 演示四件事：
#   1. 脚本规则和 AOT 规则逐字节一致（loot.compare）
#   2. 改文件 + GM reload，掉率立刻变，进程不重启（pid 不变）
#   3. 改回去再 reload，能回滚
#   4. 灰度 30%：uid 1001 和 1002 稳定落在不同版本上
#
# 不需要 docker / 数据库，只用本机 TCP。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
PORT=9603
GM=http://127.0.0.1:9702/gm
TOKEN=local-dev-only
N=200

# 无论怎么退出，都把 loot.php 还原——否则下次跑基线就"复现不了"
restore_loot() {
    if [ -f scripts/loot.php.bak ]; then
        cp scripts/loot.php.bak scripts/loot.php
        rm -f scripts/loot.php.bak
    fi
}
trap restore_loot EXIT

KEEP=0
for a in "$@"; do
    [ "$a" = "--keep" ] && KEEP=1
done

pkill -9 -x rule_hot 2>/dev/null
sleep 0.2
rm -rf var/log var/*.log

if [ "${1:-}" != "--no-build" ]; then
    php85 "$ROOT/tsf/bin/tsf" build . || { echo "编译失败"; exit 1; }
fi

mkdir -p var/log

# 脚本层依赖 ext/interp 提供 interp_require_file / interp_invoke。
# AOT 产物是普通进程，扩展通过 ini 扫描目录注入——不需要改系统 php.ini。
INTERP="$ROOT/tsf/ext/interp/modules/interp.so"
if [ ! -f "$INTERP" ]; then
    # 77 = 用例自己声明"缺外部依赖"，回归里算 SKIP 而不是 FAIL
    echo "SKIP 缺少 $INTERP（bash tsf/ext/interp/build.sh）"
    exit 77
fi
mkdir -p /tmp/tsf-rule-hot-conf
echo "extension=$INTERP" > /tmp/tsf-rule-hot-conf/interp.ini
INI="/tmp/tsf-rule-hot-conf:/usr/local/php-8.5-zts/etc/conf.d"

# 脚本单测：不编译、不起服务、不需要端口。脚本是运营每周改的东西，
# 没有秒级反馈的测试等于每次改都在赌
echo "── 0. 脚本单测 ──"
PHP_INI_SCAN_DIR="$INI" php85 tests/script/run.php || { echo "脚本单测失败"; exit 1; }
echo

PHP_INI_SCAN_DIR="$INI" ./rule_hot > /tmp/rule_hot_server.log 2>&1 &
for _ in $(seq 1 100); do
    if ss -ltn 2>/dev/null | grep -q ":$PORT "; then break; fi
    sleep 0.1
done
if ! ss -ltn 2>/dev/null | grep -q ":$PORT "; then
    echo "启动超时，看 /tmp/rule_hot_server.log"; exit 1
fi

PID=$(pgrep -x rule_hot | head -1)
echo "服务已启动 pid=$PID  游戏 $PORT  GM 9702"
echo

gm() {
    curl -s -X POST "$GM" -H "X-GM-Token: $TOKEN" -d "$1"
}

line() {
    printf '  %-10s %s\n' "$1" "$2"
}

echo "── 1. 契约对齐：脚本 v1 与 AOT 规则必须逐字节一致 ──"
CMP=$(gm '{"cmd":"loot.compare","args":{"uid":1001,"kills":50}}')
line "compare" "$CMP"
case "$CMP" in
    *same=yes*) line "判定" "一致，后面看到的差异只可能来自规则本身" ;;
    *) line "判定" "!! 不一致，停止：这个 demo 的前提已经不成立"; exit 1 ;;
esac
echo

echo "── 2. 基线（loot.php v1：武器 25% / 材料 50% / 稀有 2%）──"
php85 client.php $PORT tok-1001 $N before | tee /tmp/rh_before.txt
sleep 2   # 等脚本挂的延时奖励（每 10 次 +50）落地
line "status" "$(gm '{"cmd":"script.status","args":{}}' | sed 's/.*"data"://; s/}$//')"
echo

echo "── 3. 热更：用 loot.hotfix.php 覆盖，GM reload（不重启）──"
cp scripts/loot.php scripts/loot.php.bak
cp scripts/loot.hotfix.php scripts/loot.php
line "reload" "$(gm '{"cmd":"script.reload","args":{"name":"loot"}}')"
php85 client.php $PORT tok-1001 $N after | tee /tmp/rh_after.txt
sleep 1
line "status" "$(gm '{"cmd":"script.status","args":{}}' | sed 's/.*"data"://; s/}$//')"
echo

echo "── 4. 回滚：还原 loot.php，再 reload ──"
cp scripts/loot.php.bak scripts/loot.php
line "reload" "$(gm '{"cmd":"script.reload","args":{"name":"loot"}}')"
php85 client.php $PORT tok-1001 $N rollback | tee /tmp/rh_rollback.txt
echo

echo "── 5. 灰度 30%：同一份代码，两个 uid 走不同版本 ──"
line "gray" "$(gm '{"cmd":"script.gray","args":{"name":"loot","pct":30}}')"
php85 client.php $PORT tok-1001 $N gray-a | tee /tmp/rh_gray_a.txt
php85 client.php $PORT tok-1002 $N gray-b | tee /tmp/rh_gray_b.txt
line "说明" "uid 的散列落在哪一侧是固定的；0% 全走 base，100% 全走 hotfix"
line "复位" "$(gm '{"cmd":"script.gray","args":{"name":"loot","pct":0}}')"
echo

# 6. 汇总：每段增量（items 是累计值，差分才是掉率）
items_of() {
    grep -o 'items=[0-9]*' "$1" | head -1 | cut -d= -f2
}
B0=0
B1=$(items_of /tmp/rh_before.txt)
A1=$(items_of /tmp/rh_after.txt)
R1=$(items_of /tmp/rh_rollback.txt)
GA1=$(items_of /tmp/rh_gray_a.txt)
GB1=$(items_of /tmp/rh_gray_b.txt)

per() {
    awk -v d="$1" -v n="$N" 'BEGIN{printf "%.2f", d/n}'
}

echo "── 汇总（items 为累加值，差值是这 200 次的掉落件数）──"
printf '  %-10s %6s  %6s   %s\n' "段" "件数" "件/次" "规则"
printf '  %-10s %6s  %6s   %s\n' "before" "$((B1-B0))" "$(per $((B1-B0)))" "v1：武器 25% / 材料 50% / 稀有 2%"
printf '  %-10s %6s  %6s   %s\n' "after" "$((A1-B1))" "$(per $((A1-B1)))" "hotfix：55% / 50% / 10%"
printf '  %-10s %6s  %6s   %s\n' "rollback" "$((R1-A1))" "$(per $((R1-A1)))" "回滚到 v1"
printf '  %-10s %6s  %6s   %s\n' "gray 1001" "$((GA1-R1))" "$(per $((GA1-R1)))" "30% 灰度未命中 → base"
printf '  %-10s %6s  %6s   %s\n' "gray 1002" "$((GB1-GA1))" "$(per $((GB1-GA1)))" "30% 灰度命中 → hotfix"
# 灰度分流的断言：hotfix 掉率约 1.15/次，base 约 0.78/次，
# 差 20% 以上才算"真的走到了不同版本"，而不是随机波动
GB_D=$((GB1-GA1))
GA_D=$((GA1-R1))
if [ "$GB_D" -gt $((GA_D * 6 / 5)) ]; then
    echo "  ✓ 灰度分流生效：hit=$GB_D 件 > miss=$GA_D 件"
else
    echo "  ✗ 灰度分流没生效：hit=$GB_D miss=$GA_D（期望 hit 明显更大）"
    exit 1
fi
echo

# 7. pid 不变 = 全程没有重启
PID2=$(pgrep -x rule_hot | head -1)
if [ "$PID" = "$PID2" ]; then
    echo "✓ 全程 pid=$PID 未变：三次规则切换都是热更，没有重启"
else
    echo "✗ pid 变了（$PID → $PID2）：说明中间发生过重启，结论不可信"
    exit 1
fi

echo
echo "应用日志：var/log/app.log     服务端 stdout：/tmp/rule_hot_server.log"
echo "GM 例子："
echo "  curl -s -X POST $GM -H 'X-GM-Token: $TOKEN' -d '{\"cmd\":\"script.status\",\"args\":{}}'"
echo
if [ "$KEEP" = "1" ]; then
    echo "服务保留在跑（pid=$PID），清理：pkill -x rule_hot"
else
    pkill -9 -x rule_hot 2>/dev/null
    echo "已停止服务（要看它继续跑就加 --keep）"
fi
