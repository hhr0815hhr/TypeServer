#!/bin/bash
# drain 验收：退役时"停止接新房、存量打完、自己退出"。
#
# 验四件事：
#   1. `./duel drain` 之后服务端秒级内进入 drain（日志有 drain started）
#   2. 新玩家匹配被**明确回绝**（NOTICE draining），不是静默等
#   3. 正在打的那一局照常打完（客户端拿到 RESULT）
#   4. 存量清零后进程自己退出（不需要 kill -9），日志有 drain done
set -u

# 端口从 9101 起挑空闲的：开发机上固定端口常被别的服务占着（本机是 mir_*），
# 写死的话客户端会连到别人的进程，几十秒后给出一堆和代码无关的 FAIL。


cd "$(dirname "$0")" || exit 1
. ../../scripts/lib.sh

P0=$(pick_port 9101) || { echo "SKIP 9101 起连续 50 个端口都被占用"; exit 77; }

# duel 是编译产物、不入库。没编过就先编——否则新克隆/CI 会一路跑到「0. 清场」
# 才因为 ./duel 不存在而失败，而本地编过的环境是绿的（假绿比报错更费时间）。
[ -x ./duel ] || ../../tsf/bin/tsf build . > /tmp/duel_build.log 2>&1

PASS=0
FAIL=0
ok()  { PASS=$((PASS + 1)); printf 'PASS %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf 'FAIL %s\n' "$1"; }

cleanup() { pkill -9 -x duel 2>/dev/null; sleep 0.2; }
trap cleanup EXIT


echo "=== 0. 清场 ==="
cleanup
rm -rf var/snap var/log var/settle.log var/settle.n*.log var/duel.db var/node*.lock var/drain.n*
mkdir -p var/snap var/log
./duel migrate > /dev/null 2>&1 || { echo "迁移失败"; exit 1; }

echo
echo "=== 1. 起服务，先打一局（让它处于"有存量"状态）==="
TSF_PORT=$P0 TSF_PORT_AUTO=1 ./duel > /tmp/dr_srv.log 2>&1 &
SRV=$!
wait_port $P0 || { echo "没起来"; exit 1; }
sleep 0.5

php85 client.php $P0 tok-1001 aggressive > /tmp/dr_a.log 2>&1 &
CL_A=$!
php85 client.php $P0 tok-1002 passive > /tmp/dr_b.log 2>&1 &
CL_B=$!

# 等到真正开打（客户端打出匹配成功那行）
for _ in $(seq 1 40); do
    grep -q "MATCHED" /tmp/dr_a.log 2>/dev/null && break
    sleep 0.25
done
if grep -q "MATCHED" /tmp/dr_a.log; then ok "对局已开始（有存量）"; else bad "对局没开始"; fi

echo
echo "=== 2. 中途通知退役（写 drain 文件，不是 SIGTERM）==="
#
# 为什么不用 SIGTERM：SWOOLE_PROCESS 模式下 master 的 SIGTERM 由 Swoole 自己接管，
# 用户注册的 handler 不触发（顶层和 Start 回调都实测过）。drain 走文件轮询，
# 容器里也能用。k8s 场景见 demo/README.md 的 preStop 写法。
OUT=$(./duel drain 2>&1)
echo "   $OUT" | head -1
sleep 1.5
if grep -q "drain started" var/log/app.log; then
    ok "服务端轮询到 drain 文件，进入退役"
else
    bad "没有 drain started 日志"
fi

echo
echo "=== 3. 新玩家匹配应被明确回绝 ==="
php85 client.php $P0 tok-2001 aggressive > /tmp/dr_c.log 2>&1
RC=$?
if grep -q "NOTICE draining" /tmp/dr_c.log; then
    ok "新匹配收到 NOTICE draining"
else
    bad "新匹配没收到明确回绝：$(tail -1 /tmp/dr_c.log)"
fi
if [ "$RC" = "3" ]; then ok "客户端以退出码 3 结束（通知路径）"; else bad "客户端退出码 $RC（期望 3）"; fi

echo
echo "=== 4. 存量那一局照常打完 ==="
wait $CL_A 2>/dev/null
wait $CL_B 2>/dev/null
if grep -q "RESULT" /tmp/dr_a.log; then ok "被打断不了：老对局拿到了 RESULT"; else bad "老对局没打完"; fi

echo
echo "=== 5. 存量清零后自己退出 ==="
GONE=0
for _ in $(seq 1 40); do
    if ! kill -0 $SRV 2>/dev/null; then GONE=1; break; fi
    sleep 0.5
done
if [ "$GONE" = "1" ]; then ok "进程自己退出了（不需要 kill -9）"; else bad "进程还活着"; fi
if grep -q "drain done" var/log/app.log; then ok "日志有 drain done"; else bad "没看到 drain done"; fi

echo
echo "=== 6. 退役期间的新房号没有被消耗 ==="
# drain 后没有新建房，所以结算日志里只该有开打那一局
N=$(grep -c '"k":"duel.settled"' var/settle.n0.log 2>/dev/null || echo 0)
if [ "$N" = "1" ]; then ok "只结算了存量那一局（1 条）"; else bad "结算事件 $N 条（期望 1）"; fi

echo
echo "=== 判定 ==="
echo "PASS $PASS 项 / FAIL $FAIL 项"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
