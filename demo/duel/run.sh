#!/usr/bin/env bash
# 端到端跑一局：起服务端 → 两个客户端（一个进攻一个被动）→ 输出结算。
set -u
cd "$(dirname "$0")" || exit 1
# 迁移是部署步骤：先建表，再起服务（服务启动只校验，不建表）。
# 幂等，跑几次都行。
./duel migrate > /tmp/duel_migrate.log 2>&1 || { echo "迁移失败，看 /tmp/duel_migrate.log"; exit 1; }
pkill -9 -x duel 2>/dev/null
pkill -9 -f "duel/client.php" 2>/dev/null
sleep 0.3

# 清掉上次留下的快照。不清的话，`run-crash.sh` 跑完之后再来跑这一个，服务启动会把
# 那个"已结算但还占着 1001/1002"的房间恢复出来，于是新的一局永远配不上对——
# 现象就是两个客户端都卡在 "matching..."，看起来像匹配器坏了。
rm -rf var/snap
mkdir -p var/snap

./duel > /tmp/duel_server.log 2>&1 &
for _ in $(seq 1 100); do
  if ss -ltn 2>/dev/null | grep -q ":9101 "; then break; fi
  sleep 0.1
done

php85 client.php 9101 tok-1001 aggressive > /tmp/duel_a.log 2>&1 &
php85 client.php 9101 tok-1002 passive > /tmp/duel_b.log 2>&1 &

sleep 15

echo "=== A (aggressive) ==="
tail -6 /tmp/duel_a.log
echo "=== B (passive) ==="
tail -6 /tmp/duel_b.log
echo "=== 服务端 ==="
tail -5 /tmp/duel_server.log

pkill -9 -x duel 2>/dev/null
pkill -9 -f "duel/client.php" 2>/dev/null
