#!/usr/bin/env bash
# 断线判负验证：开局 5 秒后杀掉一方，另一方应**立刻**收到结算，
# 而不是干等 60 秒超时。
set -u
cd "$(dirname "$0")" || exit 1
pkill -9 -x duel 2>/dev/null
pkill -9 -f "duel/client.php" 2>/dev/null
sleep 0.3

./duel > /tmp/duel_s2.log 2>&1 &
for _ in $(seq 1 100); do
  if ss -ltn 2>/dev/null | grep -q ":9101 "; then break; fi
  sleep 0.1
done

php85 client.php 9101 tok-1001 aggressive > /tmp/duel_fa.log 2>&1 &
A=$!
php85 client.php 9101 tok-1002 passive > /tmp/duel_fb.log 2>&1 &
B=$!

sleep 5
kill -9 "$A" 2>/dev/null
echo "=== 已杀掉 A(1001)，等 3 秒看 B 的反应 ==="
sleep 3

echo "=== B 的输出 ==="
tail -4 /tmp/duel_fb.log
echo "=== A 的最后状态 ==="
tail -2 /tmp/duel_fa.log

pkill -9 -x duel 2>/dev/null
kill -9 "$B" 2>/dev/null
