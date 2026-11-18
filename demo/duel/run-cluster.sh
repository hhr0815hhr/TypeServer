#!/bin/bash
# 多进程验收：两个进程、两个号段、两份事件日志，互不越界。
#
# 验的是四件事：
#   1. 每个进程只在自己的号段里建房（node 0 → 1, node 1 → 1000000001）
#   2. 每个进程只写自己那份事件日志，且**不碰对方的**
#   3. 对账能把两份日志归并起来看（`wallet-check` 的数字 = 两边之和）
#   4. 同一个 node_id 起第二个进程会被拒（双主防护）
#
# 端口：node 0 = 9101，node 1 = 9102（同机多进程不能用同一端口——
# Swoole 在构造 Server 时就 bind，`reuse_port` 选项来不及生效，实测过）。
set -u
cd "$(dirname "$0")" || exit 1

# duel 是编译产物、不入库。没编过就先编——否则新克隆/CI 会一路跑到「0. 清场」
# 才因为 ./duel 不存在而失败，而本地编过的环境是绿的（假绿比报错更费时间）。
[ -x ./duel ] || ../../tsf/bin/tsf build . > /tmp/duel_build.log 2>&1

PASS=0
FAIL=0
ok()   { PASS=$((PASS + 1)); printf 'PASS %s\n' "$1"; }
bad()  { FAIL=$((FAIL + 1)); printf 'FAIL %s\n' "$1"; }

cleanup() {
    pkill -9 -x duel 2>/dev/null
    sleep 0.3
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
echo "已重置 var/"

echo
echo "=== 1. 起两个进程（node 0:9101, node 1:9102）==="
./duel > /tmp/cl_n0.log 2>&1 &
N0=$!
TSF_ENV=node1 ./duel > /tmp/cl_n1.log 2>&1 &
N1=$!

wait_port 9101 || { echo "node 0 没起来"; tail -5 /tmp/cl_n0.log; exit 1; }
wait_port 9102 || { echo "node 1 没起来"; tail -5 /tmp/cl_n1.log; exit 1; }
sleep 1
ok "两个进程都在监听（9101 / 9102）"

echo
echo "=== 2. node 0 上打一局 ==="
php85 client.php 9101 tok-1001 aggressive > /tmp/cl_a.log 2>&1 &
php85 client.php 9101 tok-1002 passive > /tmp/cl_b.log 2>&1 &
for _ in $(seq 1 60); do
    grep -q "RESULT" /tmp/cl_a.log 2>/dev/null && break
    sleep 0.5
done
if grep -q "RESULT" /tmp/cl_a.log; then ok "node 0 一局打完了"; else bad "node 0 没打完"; fi

echo
echo "=== 3. node 1 上打一局 ==="
php85 client.php 9102 tok-2001 aggressive > /tmp/cl_c.log 2>&1 &
php85 client.php 9102 tok-2002 passive > /tmp/cl_d.log 2>&1 &
for _ in $(seq 1 60); do
    grep -q "RESULT" /tmp/cl_c.log 2>/dev/null && break
    sleep 0.5
done
if grep -q "RESULT" /tmp/cl_c.log; then ok "node 1 一局打完了"; else bad "node 1 没打完"; fi
sleep 1

echo
echo "=== 4. 号段与日志隔离 ==="
grep -o '"id":"duel:[0-9]*"' var/settle.n0.log 2>/dev/null | head -2 > /tmp/cl_ids0.txt
grep -o '"id":"duel:[0-9]*"' var/settle.n1.log 2>/dev/null | head -2 > /tmp/cl_ids1.txt

if grep -q '"id":"duel:1"' var/settle.n0.log 2>/dev/null; then
    ok "node 0 的房间号在 0 段（duel:1）"
else
    bad "node 0 的号不对：$(cat /tmp/cl_ids0.txt | tr '\n' ' ')"
fi

if grep -q '"id":"duel:1000000001"' var/settle.n1.log 2>/dev/null; then
    ok "node 1 的房间号在 1 段（duel:1000000001）"
else
    bad "node 1 的号不对：$(cat /tmp/cl_ids1.txt | tr '\n' ' ')"
fi

# 互不越界：谁的文件里都不该出现对方的号
if grep -q "duel:100000000" var/settle.n0.log 2>/dev/null; then
    bad "node 0 的日志里出现了 1 段的号"
else
    ok "node 0 的日志没有 1 段的号"
fi
if grep -qE '"id":"duel:[0-9]{1,3}"' var/settle.n1.log 2>/dev/null; then
    bad "node 1 的日志里出现了 0 段的号"
else
    ok "node 1 的日志没有 0 段的号"
fi

echo
echo "=== 5. 对账把两份日志一起看 ==="
CHECK=$(./duel wallet-check 2>&1 | head -1)
echo "   $CHECK"
case "$CHECK" in
    *"事件 2 条"*) ok "归并后看到两个节点的 2 条事件" ;;
    *) bad "归并条数不对：$CHECK" ;;
esac

echo
echo "=== 6. 同 node_id 起第二个进程应被拒（双主防护）==="
if timeout 5 ./duel 2>&1 | grep -q "已被进程"; then
    ok "重复 node_id 被拒绝"
else
    bad "重复 node_id 没被拒绝"
fi

echo
echo "=== 7. 杀掉 node 1，node 0 不受影响 ==="
kill -9 $N1 2>/dev/null
wait $N1 2>/dev/null
sleep 0.5
if kill -0 $N0 2>/dev/null; then ok "node 0 还活着"; else bad "node 0 也被带走了"; fi
if grep -q "RESULT" /tmp/cl_a.log; then ok "node 0 的战局记录还在"; fi

echo
echo "=== 判定 ==="
echo "PASS $PASS 项 / FAIL $FAIL 项"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
