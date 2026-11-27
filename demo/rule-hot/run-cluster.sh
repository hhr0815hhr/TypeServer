#!/usr/bin/env bash
# 跨节点热更：证明"只 reload 本机不够"，以及 Broadcast 怎么把重载送到另一个进程。
#
#   ./run-cluster.sh            编译 + 跑完整验证
#   ./run-cluster.sh --no-build 跳过编译
#
# 四个阶段：
#   1. 起两个独立进程（节点 1 / 2），Cluster 互连
#   2. before        —— 两边都是 v1（低掉率）
#   3. local-only    —— 改文件 + 只 reload 节点 1：节点 1 变了，节点 2 **没变**
#   4. broadcast     —— GM 广播重载：两个节点都变
#
# 端口：9603/9613/9711（节点 1）、9604/9614/9712（节点 2）。
set -u
# 关掉作业控制消息：cleanup 里 pkill 会让 bash 打印一堆 "Killed"，
# 和断言结果混在一起，看起来像失败了
set +m
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
N=200
GM1=http://127.0.0.1:9711/gm
GM2=http://127.0.0.1:9712/gm
TOKEN=local-dev-only

restore_loot() {
    if [ -f scripts/loot.php.bak ]; then
        cp scripts/loot.php.bak scripts/loot.php
        rm -f scripts/loot.php.bak
    fi
}
cleanup() {
    pkill -9 -x rule_hot 2>/dev/null
    # 回收子进程状态，否则 bash 会在退出时打印两条 "Killed"，
    # 和断言结果混在一起看着像失败
    wait 2>/dev/null
    restore_loot
}
trap cleanup EXIT

cleanup
rm -rf var/log
sleep 0.3

if [ "${1:-}" != "--no-build" ]; then
    php85 "$ROOT/tsf/bin/tsf" build . || { echo "编译失败"; exit 1; }
fi

INTERP="$ROOT/tsf/ext/interp/modules/interp.so"
if [ ! -f "$INTERP" ]; then
    echo "SKIP 缺少 $INTERP（bash tsf/ext/interp/build.sh）"
    exit 77
fi
mkdir -p /tmp/tsf-rule-hot-conf
echo "extension=$INTERP" > /tmp/tsf-rule-hot-conf/interp.ini
INI="/tmp/tsf-rule-hot-conf:/usr/local/php-8.5-zts/etc/conf.d"

mkdir -p var/log

echo "── 起两个节点 ──"
PHP_INI_SCAN_DIR="$INI" \
RULE_HOT_NODE=1 RULE_HOT_CLUSTER_ADDR=tcp://127.0.0.1:9613 \
RULE_HOT_PEER_NODE=2 RULE_HOT_PEER_ADDR=tcp://127.0.0.1:9614 \
RULE_HOT_GM_PORT=9711 TSF_PORT=9603 \
    ./rule_hot > /tmp/rh_node1.log 2>&1 &

PHP_INI_SCAN_DIR="$INI" \
RULE_HOT_NODE=2 RULE_HOT_CLUSTER_ADDR=tcp://127.0.0.1:9614 \
RULE_HOT_PEER_NODE=1 RULE_HOT_PEER_ADDR=tcp://127.0.0.1:9613 \
RULE_HOT_GM_PORT=9712 TSF_PORT=9604 \
    ./rule_hot > /tmp/rh_node2.log 2>&1 &

for _ in $(seq 1 100); do
    if ss -ltn 2>/dev/null | grep -q ":9603 " && ss -ltn 2>/dev/null | grep -q ":9604 "; then break; fi
    sleep 0.1
done

gm() {
    curl -s -X POST "$1" -H "X-GM-Token: $TOKEN" -d "$2"
}

# 等 Cluster 连上（watchPeer 有退避，首次通常 < 1s）
LINKED=0
for _ in $(seq 1 60); do
    S=$(gm "$GM1" '{"cmd":"cluster.status","args":{}}')
    case "$S" in
        *"peers=1"*) LINKED=1; break ;;
    esac
    sleep 0.1
done
if [ "$LINKED" != "1" ]; then
    echo "!! 两个节点没连上：$S"
    echo "   看 /tmp/rh_node1.log 与 /tmp/rh_node2.log"
    exit 1
fi
echo "  已连上：$(gm "$GM1" '{"cmd":"cluster.status","args":{}}' | sed 's/.*"data"://; s/}$//')"
echo

items_of() {
    grep -o 'items=[0-9]*' | head -1 | cut -d= -f2
}

run_client() {
    php85 client.php "$1" tok-1001 "$N" "$2" | tee "/tmp/rh_$2.txt"
}

echo "── 1. before：两边都是 v1 ──"
run_client 9603 before_n1
run_client 9604 before_n2
echo

echo "── 2. 改文件，只 reload 节点 1（本机） ──"
cp scripts/loot.php scripts/loot.php.bak
cp scripts/loot.hotfix.php scripts/loot.php
echo "  node1: $(gm "$GM1" '{"cmd":"script.reload","args":{"name":"loot"}}')"
run_client 9603 local_n1
run_client 9604 local_n2
echo

echo "── 3. 广播重载（节点 1 → 节点 2） ──"
RESP=$(gm "$GM1" '{"cmd":"script.reload-cluster","args":{"name":"loot"}}')
echo "  node1: $RESP"
REQ=$(echo "$RESP" | grep -o 'reqId=[^"}]*' | cut -d= -f2)
if [ -z "$REQ" ]; then
    echo "!! 没拿到 reqId"; exit 1
fi

DONE=0
for _ in $(seq 1 60); do
    ST=$(gm "$GM1" "{\"cmd\":\"script.cluster-status\",\"args\":{\"req\":\"$REQ\"}}")
    case "$ST" in
        *"done=1"*) DONE=1; break ;;
    esac
    sleep 0.1
done
echo "  status: $(echo "$ST" | sed 's/.*"data"://; s/}$//')"
if [ "$DONE" != "1" ]; then
    echo "!! 广播没有全部 ack（超时）"; exit 1
fi

run_client 9603 after_n1
run_client 9604 after_n2
echo

# ===== 断言 =====
B1=$(items_of < /tmp/rh_before_n1.txt)
BN2=$(items_of < /tmp/rh_before_n2.txt)
L1=$(items_of < /tmp/rh_local_n1.txt)
LN2=$(items_of < /tmp/rh_local_n2.txt)
A1=$(items_of < /tmp/rh_after_n1.txt)
AN2=$(items_of < /tmp/rh_after_n2.txt)

D_B1=$((B1)); D_BN2=$((BN2))
D_L1=$((L1-B1)); D_LN2=$((LN2-BN2))
D_A1=$((A1-L1)); D_AN2=$((AN2-LN2))

echo "── 汇总（每 200 次的掉落件数）──"
printf '  %-22s %-6s %s\n' "阶段" "node1" "node2"
printf '  %-22s %-6s %s\n' "before（v1）" "$D_B1" "$D_BN2"
printf '  %-22s %-6s %s\n' "只 reload 本机" "$D_L1" "$D_LN2"
printf '  %-22s %-6s %s\n' "广播重载后" "$D_A1" "$D_AN2"
echo

FAIL=0
# 1) 只 reload 本机时，node2 必须还是旧规则（增量与 before 同档）
if [ "$D_LN2" -lt $((D_BN2 * 6 / 5)) ]; then
    echo "  ✓ local-only：node2 仍是旧规则（$D_LN2 vs before $D_BN2）"
else
    echo "  ✗ local-only：node2 也变了（$D_LN2），说明它读到了新文件却没被 reload——判据有问题"
    FAIL=1
fi
# 2) node1 本机确实变成新规则
if [ "$D_L1" -gt $((D_B1 * 6 / 5)) ]; then
    echo "  ✓ local-only：node1 已切到新规则（$D_L1 vs before $D_B1）"
else
    echo "  ✗ local-only：node1 没变（$D_L1）"
    FAIL=1
fi
# 3) 广播后 node2 也变了
if [ "$D_AN2" -gt $((D_LN2 * 6 / 5)) ]; then
    echo "  ✓ broadcast：node2 被广播重载（$D_AN2 vs local-only $D_LN2）"
else
    echo "  ✗ broadcast：node2 没被重载（$D_AN2）"
    FAIL=1
fi

echo
if [ "$FAIL" = "0" ]; then
    echo "✓ 结论：单机 reload 只影响本进程；广播才是多节点一致的手段"
else
    echo "✗ 有断言失败，见上"
fi

exit "$FAIL"
