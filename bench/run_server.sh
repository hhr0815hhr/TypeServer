#!/usr/bin/env bash
# TSF 服务端每消息成本测量
#   起 demo_echo（1 worker），用 C 压测端 closed-loop 打不同并发，
#   同时采样服务端 CPU，折算 µs CPU/消息。
#
# ## 两个前提，缺一个就测出 0 msg/s
#
# 1. 压测端要先编出来（以前脚本不编，只有 .c 没有二进制 → ./load_tsf 不存在，
#    OUT 为空 → bc 报 "Divide by zero"）。
# 2. 服务端要关鉴权、放开限流（以前 demo-echo 没有 auth，后来加了 require=true，
#    压测端不带 token 就被回 ERR，recv 恒为 0）。所以这里用 TSF_ENV=bench 叠加
#    config/tsf.bench.json —— 不去改压测端，是为了让这个数字和历史上的 37k 可比：
#    两者量的都是"无鉴权、无限流"的纯 echo 路径。
set -u
cd "$(dirname "$0")" || exit 1

SRV=/data/TypeServer/demo/demo-echo/demo_echo
LOAD=./load_tsf
export TSF_ENV=bench

cpu_ticks() { awk '{print $14+$15}' "/proc/$1/stat" 2>/dev/null; }

if [ ! -x "$SRV" ]; then
    echo "服务端未构建：先 cd demo/demo-echo && tsf build ."
    exit 1
fi

CFG=/data/TypeServer/demo/demo-echo/config/tsf.bench.json
if [ ! -f "$CFG" ]; then
    echo "缺少 $CFG —— 没有它服务端仍是 auth.require=true，压测端不带 token 会一条回包都收不到"
    exit 1
fi

# 压测端：源文件比二进制新（或二进制不存在）就重编
if [ ! -x "$LOAD" ] || [ load_tsf.c -nt "$LOAD" ]; then
    echo "编译压测端 load_tsf ..."
    if ! gcc -O2 -o load_tsf load_tsf.c -lpthread; then
        echo "编译失败"
        exit 1
    fi
fi

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
  MSG=${MSG:-0}
  if [ "$MSG" -eq 0 ]; then
    # 不把除零丢给 bc：那行 "Runtime error (func=(main), adr=24)" 完全看不出
    # 是"一条回包都没收到"
    printf "  conns=%-3s 消息=0  —— 一条回包都没收到。检查：演示端是否以 TSF_ENV=bench 启动、\n" "$C"
    printf "            config/tsf.bench.json 是否存在（关鉴权）、服务端日志 /tmp/tsf_srv_%s.log\n" "$C"
    US="-"
  else
    US=$(echo "scale=2; ($C1 - $C0) * 10000 / $MSG" | bc)
  fi
  printf "  conns=%-3s 消息=%-8s 速率=%-8s msg/s  p50=%sms p99=%sms  CPU=%s us/msg\n" \
    "$C" "$MSG" "$RATE" "$P50" "$P99" "$US"
  pkill -9 -x demo_echo 2>/dev/null
  sleep 0.4
  "$SRV" > "/tmp/tsf_srv_$C.log" 2>&1 &
  sleep 1.2
  SPID=$(pgrep -x demo_echo | head -1)
done

pkill -9 -x demo_echo 2>/dev/null
echo
echo "done"
