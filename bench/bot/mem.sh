#!/usr/bin/env bash
# 看服务端内存随连接数怎么涨（判断是否 per-connection 泄漏/缓冲膨胀）
set -u
CONNS=${1:-5000}
cd /data/TypeServer/bench/bot || exit 1
ulimit -n 65535 2>/dev/null
pkill -9 -x demo_echo 2>/dev/null
sleep 0.3

/data/TypeServer/demo/demo-echo/demo_echo >/tmp/mem_srv.log 2>&1 &
sleep 1

RSS0=$(pgrep -x demo_echo | xargs -I{} awk '/VmRSS/{print $2}' /proc/{}/status 2>/dev/null | head -1)
echo "起始 RSS: ${RSS0:-0} KB"

./bot 127.0.0.1 9001 "$CONNS" 1 8 >/tmp/mem_bot.log 2>&1 &
BOT=$!
for i in $(seq 1 30); do
  sleep 1
  EST=$(ss -tn state established '( sport = :9001 )' 2>/dev/null | grep -c 9001)
  RSS=$(pgrep -x demo_echo | xargs -I{} awk '/VmRSS/{print $2}' /proc/{}/status 2>/dev/null | head -1)
  echo "t=${i}s established=${EST} rss=${RSS:-dead} KB"
done
wait $BOT
pkill -9 -x demo_echo 2>/dev/null
echo "--- bot ---"
grep -E "建连阶段|sent=|连接错误" /tmp/mem_bot.log
echo "--- server tail ---"
tail -3 /tmp/mem_srv.log
