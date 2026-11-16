#!/usr/bin/env bash
# 节点下线收敛验证：起一个节点进程 + 一个观察进程，kill -9 节点，量多久被发现。
#
# 关键：kill -9 而不是 kill。优雅退出会走 onStop → unregister，那是"立刻消失"，
# 测不出 TTL 判定这条真正依赖的路径。
set -u
cd "$(dirname "$0")" || exit 1
BIN=./discovery_live
DIR=/tmp/tsf_disc_live

pkill -9 -x discovery_live 2>/dev/null
rm -rf "$DIR"
mkdir -p "$DIR"
sleep 0.2

echo "=== 1. 起节点（心跳 500ms / TTL 3000ms）==="
$BIN node 60 > /tmp/disc_node.log 2>&1 &
NODE=$!
sleep 1

cat /tmp/disc_node.log
echo "--- 注册目录 ---"
ls -1 "$DIR" 2>/dev/null | head -4

echo
echo "=== 2. 起观察者 ==="
$BIN watch 12 > /tmp/disc_watch.log 2>&1 &
WATCH=$!

# 让观察者先看到"活着"（至少扫两轮）
sleep 1.2
echo "观察者已在跑，当前目录内容："
ls -1 "$DIR" 2>/dev/null | head -4

echo
echo "=== 3. kill -9 节点（不给它注销的机会）==="
kill -9 $NODE 2>/dev/null
wait $NODE 2>/dev/null
echo "已强杀，开始计时（TTL=3000ms）"

echo
echo "=== 4. 观察结果 ==="
wait $WATCH 2>/dev/null
cat /tmp/disc_watch.log

pkill -9 -x discovery_live 2>/dev/null
rm -rf "$DIR"

if grep -q "^PASS" /tmp/disc_watch.log; then
  exit 0
fi
exit 1
