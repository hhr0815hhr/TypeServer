#!/usr/bin/env bash
# TSF 服务端每消息成本测量
#   起 demo_echo（1 worker），用 C 压测端 closed-loop 打不同并发，
#   同时采样服务端 CPU，折算 µs CPU/消息。
set -u
cd /data/TypeServer/bench || exit 1

SRV=/data/TypeServer/demo/demo-echo/demo_echo
cpu_ticks() { awk '{print $14+$15}' "/proc/$1/stat" 2>/dev/null; }

pkill -9 -x demo_echo 2>/dev/null
sleep 0.5
"$SRV" > /tmp/tsf_srv.log 2>&1 &
sleep 1.5
SPID=$(pgrep -x demo_echo | head -1)
[ -z "$SPID" ] && { echo "服务端未启动"; cat /tmp/tsf_srv.log; exit 1; }
echo "server pid=$SPID"

echo
echo "== closed-loop 吞吐（1 worker）=="
for C in 1 8 32; do
  C0=$(cpu_ticks "$SPID")
  OUT=$(./load_tsf 127.0.0.1 9001 "$C" 4 | grep SUMMARY)
  C1=$(cpu_ticks "$SPID")
  MSG=$(echo "$OUT" | sed -n 's/.*recv=\([0-9]*\).*/\1/p')
  RATE=$(echo "$OUT" | sed -n 's/.*rate=\([0-9]*\).*/\1/p')
  P50=$(echo "$OUT" | sed -n 's/.*p50=\([0-9.]*\)ms.*/\1/p')
  P99=$(echo "$OUT" | sed -n 's/.*p99=\([0-9.]*\)ms.*/\1/p')
  US=$(echo "scale=2; ($C1 - $C0) * 10000 / $MSG" | bc)
  printf "  conns=%-3s 消息=%-8s 速率=%-8s msg/s  p50=%sms p99=%sms  CPU=%s us/msg\n" \
    "$C" "$MSG" "$RATE" "$P50" "$P99" "$US"
  send_clean=1
  pkill -9 -x demo_echo 2>/dev/null
  sleep 0.4
  "$SRV" > /tmp/tsf_srv_$C.log 2>&1 &
  sleep 1.2
  SPID=$(pgrep -x demo_echo | head -1)
done

pkill -9 -x demo_echo 2>/dev/null
echo
echo "done"
