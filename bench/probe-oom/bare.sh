#!/usr/bin/env bash
# 用裸 Swoole 服务端跑连接压测，看是否也 OOM。
# 用法: ./bare.sh [conns] [rate_hz] [secs]
set -u
CONNS=${1:-8500}
RATE=${2:-1}
SECS=${3:-8}

cd /data/TypeServer/bench/bot || exit 1
ulimit -n 65535 2>/dev/null

pkill -9 -f "probe-oom/bare.php" 2>/dev/null
pkill -9 -x demo_echo 2>/dev/null
sleep 0.5

PHP_OPTS=${PHP_OPTS:-}
MAXCONN=${MAXCONN:-20000}
BUFSIZE=${BUFSIZE:-65536}
SOCKBUF=${SOCKBUF:-0}
INBUF=${INBUF:-0}
WORKERS=${WORKERS:-1}
php85 $PHP_OPTS /data/TypeServer/bench/probe-oom/bare.php 9002 "$MAXCONN" "$BUFSIZE" "$SOCKBUF" "$INBUF" "$WORKERS" >/tmp/bare.log 2>&1 &

for _ in $(seq 1 60); do
  if ss -ltn 2>/dev/null | grep -q ":9002 "; then break; fi
  sleep 0.1
done

echo "--- 服务端启动信息 ---"
grep "\[bare\]" /tmp/bare.log | head -4

rss_total() {
  total=0
  for p in $(pgrep -f "probe-oom/bare.php" 2>/dev/null); do
    v=$(awk '/VmRSS/{print $2}' "/proc/$p/status" 2>/dev/null)
    total=$((total + ${v:-0}))
  done
  echo "$total"
}

echo "起始 RSS(全部进程合计): $(rss_total) KB"

./bot 127.0.0.1 9002 "$CONNS" "$RATE" "$SECS" >/tmp/bare_bot.log 2>&1 &
BOT=$!

i=0
while kill -0 $BOT 2>/dev/null; do
  i=$((i + 1))
  sleep 1
  EST=$(ss -tn state established '( sport = :9002 )' 2>/dev/null | grep -c 9002)
  echo "t=${i}s established=${EST} rss=$(rss_total) KB"
  if [ $((i % 10)) -eq 0 ]; then
    ps -eo pid,ppid,vsz,rss,args 2>/dev/null | grep "probe-oom/bare.php" | grep -v grep \
      | awk '{printf "    pid=%s ppid=%s vsz=%.0fMB rss=%.0fMB\n", $1, $2, $3/1024, $4/1024}'
  fi
done
wait $BOT
echo "--- bot ---"
grep -E "建连阶段|sent=|连接错误" /tmp/bare_bot.log
echo "--- 服务端尾部 ---"
tail -4 /tmp/bare.log
pkill -9 -f "probe-oom/bare.php" 2>/dev/null
