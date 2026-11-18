#!/bin/bash
# 崩溃恢复验证：对局打到一半 kill -9，重启后房间应当被捞回来。
#
# 关键点：用 kill -9 而不是 kill，因为优雅退出会走 onStop → 删快照，
# 那测的是"正常关闭"，不是"崩溃恢复"。

set -u
cd "$(dirname "$0")"

PORT=9101
rm -rf var/snap
mkdir -p var/snap

# 迁移是部署步骤：先建表，再起服务（服务启动只校验，不建表）。
# 幂等，跑几次都行。
./duel migrate > /tmp/duel_migrate.log 2>&1 || { echo "迁移失败，看 /tmp/duel_migrate.log"; exit 1; }
rm -f var/log/app.log

echo "=== 1. 起服务 ==="
./duel > /tmp/duel_crash.log 2>&1 &
SRV=$!
sleep 1

if ! grep -q "duel ready" var/log/app.log 2>/dev/null; then
  echo "服务没起来："
  head -5 /tmp/duel_crash.log
  kill -9 $SRV 2>/dev/null
  exit 1
fi

echo "=== 2. 两个客户端开打 ==="
php85 client.php $PORT tok-1001 aggressive > /tmp/c1_crash.log 2>&1 &
C1=$!
php85 client.php $PORT tok-1002 passive > /tmp/c2_crash.log 2>&1 &
C2=$!

# 等对局真正开始（日志里出现 MATCHED），再等 2 秒让血量掉一些
for i in $(seq 1 20); do
  if grep -q "MATCHED" /tmp/c1_crash.log 2>/dev/null; then
    break
  fi
  sleep 0.25
done
sleep 2

echo "=== 3. kill -9 服务（模拟崩溃，不走 onStop）==="
kill -9 $SRV 2>/dev/null
wait $SRV 2>/dev/null
kill -9 $C1 $C2 2>/dev/null
wait $C1 $C2 2>/dev/null

echo "--- 崩溃前客户端看到的 ---"
grep -E "MATCHED|hp=" /tmp/c1_crash.log | tail -3

echo "=== 4. 快照文件 ==="
ls -1 var/snap/*.snap 2>/dev/null | head -5
php85 show-snapshot.php

echo "=== 5. 重启服务 ==="
./duel > /tmp/duel_restart.log 2>&1 &
SRV2=$!
sleep 2

echo "--- 恢复日志 ---"
grep -E "rooms restored|bad snapshot|snapshot unusable" var/log/app.log | tail -3

echo "--- 恢复后的房间（重启服务写的新快照，应包含原状态）---"
php85 show-snapshot.php

echo "=== 6. 收尾 ==="
kill -9 $SRV2 2>/dev/null
wait $SRV2 2>/dev/null
pkill -9 -x duel 2>/dev/null
echo "done"
