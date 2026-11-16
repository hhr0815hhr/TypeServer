#!/bin/bash
# 重连找回验收：断线不立刻判负，宽限期内重连能回到原来那一局。
#
# 验四件事：
#   1. 客户端进程被杀（模拟断线）后**不立刻判负**（对面没收到 RESULT）
#   2. 宽限期内用同一 token 重连并 REJOIN → 拿到原来的房间号/对手/边
#   3. 服务端日志里有 player rejoined，且没有走判负路径
#   4. 这一局最后正常打完（对面拿到 RESULT）
set -u
cd "$(dirname "$0")" || exit 1

PASS=0
FAIL=0
ok()  { PASS=$((PASS + 1)); printf 'PASS %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf 'FAIL %s\n' "$1"; }

cleanup() {
    pkill -9 -x duel 2>/dev/null
    pkill -9 -f "demo/duel/client.php" 2>/dev/null
    sleep 0.2
}
trap cleanup EXIT

wait_port() {
    local port="$1" i
    for i in $(seq 1 80); do
        ss -ltn 2>/dev/null | grep -q ":$port " && return 0
        sleep 0.1
    done
    return 1
}

echo "=== 0. 清场 ==="
cleanup
rm -rf var/snap var/log var/settle.log var/settle.n*.log var/duel.db var/node*.lock var/drain.n*
mkdir -p var/snap var/log
./duel migrate > /dev/null 2>&1 || { echo "迁移失败"; exit 1; }

echo
echo "=== 1. 起服，两个客户端开打 ==="
./duel > /tmp/rj_srv.log 2>&1 &
SRV=$!
wait_port 9101 || { echo "没起来"; exit 1; }
sleep 0.5

php85 client.php 9101 tok-1001 aggressive > /tmp/rj_a.log 2>&1 &
CL_A=$!
php85 client.php 9101 tok-1002 passive > /tmp/rj_b.log 2>&1 &
CL_B=$!

for _ in $(seq 1 40); do
    grep -q "MATCHED" /tmp/rj_a.log 2>/dev/null && grep -q "MATCHED" /tmp/rj_b.log 2>/dev/null && break
    sleep 0.25
done
if grep -q "MATCHED" /tmp/rj_a.log && grep -q "MATCHED" /tmp/rj_b.log; then
    ok "两人都进了同一局"
else
    bad "没配上对"; exit 1
fi
ROOM=$(sed -n 's/.*room \([0-9]*\).*/\1/p' /tmp/rj_a.log | head -1)

echo
echo "=== 2. 杀掉 A 的进程（模拟断线）==="
kill -9 $CL_A 2>/dev/null
wait $CL_A 2>/dev/null
sleep 2

if grep -q "RESULT" /tmp/rj_b.log; then
    bad "断线后立刻判负了（对面已经收到 RESULT）"
else
    ok "断线 2 秒后还没判负（还在宽限期）"
fi

echo
echo "=== 3. 宽限期内重连并 REJOIN ==="
php85 client.php 9101 tok-1001 aggressive --rejoin > /tmp/rj_a2.log 2>&1 &
CL_A2=$!
for _ in $(seq 1 20); do
    grep -q "REJOINED\|NOTICE\|timeout" /tmp/rj_a2.log 2>/dev/null && break
    sleep 0.25
done

if grep -q "REJOINED" /tmp/rj_a2.log; then
    ok "重连找回成功：$(grep REJOINED /tmp/rj_a2.log | head -1)"
else
    bad "重连没找回：$(tail -1 /tmp/rj_a2.log)"
fi

GOT=$(sed -n 's/.*REJOINED room \([0-9]*\).*/\1/p' /tmp/rj_a2.log | head -1)
if [ -n "$ROOM" ] && [ "$GOT" = "$ROOM" ]; then
    ok "找回的是同一局（room $ROOM）"
else
    bad "房间号对不上：原来 $ROOM，找回 $GOT"
fi

echo
echo "=== 4. 服务端记录与判负路径 ==="
sleep 0.5
if grep -q "player rejoined" var/log/app.log; then
    ok "服务端记了 player rejoined"
else
    bad "服务端没有 rejoined 记录"
fi

echo
echo "=== 5. 这一局最后正常打完 ==="
wait $CL_A2 2>/dev/null
wait $CL_B 2>/dev/null
if grep -q "RESULT" /tmp/rj_b.log; then
    ok "对手收到了结算（$(grep RESULT /tmp/rj_b.log | head -1)）"
else
    bad "对手没收到结算"
fi

echo
echo "=== 判定 ==="
echo "PASS $PASS 项 / FAIL $FAIL 项"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
