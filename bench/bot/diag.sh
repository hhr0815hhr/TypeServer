#!/usr/bin/env bash
# 诊断：1 万连接时服务端到底接受到了多少
set -u
cd /data/TypeServer/bench/bot || exit 1
ulimit -n 65535 2>/dev/null
pkill -9 -x demo_echo 2>/dev/null
sleep 0.3

/data/TypeServer/demo/demo-echo/demo_echo >/tmp/diag_srv.log 2>&1 &
sleep 1

./bot 127.0.0.1 9001 10000 1 8 >/tmp/diag_bot.log 2>&1 &
BOT=$!
for i in $(seq 1 8); do
  sleep 1
  EST=$(ss -tn state established '( sport = :9001 )' 2>/dev/null | grep -c 9001)
  FDS=$(pgrep -x demo_echo | xargs -I{} sh -c 'ls /proc/{}/fd 2>/dev/null | wc -l' | head -1)
  LISTEN=$(ss -ltn '( sport = :9001 )' 2>/dev/null | tail -1)
  echo "t=${i}s established=${EST} server_fds=${FDS}"
  echo "      listen: ${LISTEN}"
done
wait $BOT
pkill -9 -x demo_echo 2>/dev/null
echo "--- bot ---"
tail -6 /tmp/diag_bot.log
