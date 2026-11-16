#!/usr/bin/env bash
# 连接规模 / 速率压测：起服务端 → 采 CPU → 跑 bot → 报结果
#
# 用法: ./run.sh [conns] [rate_hz] [seconds] [workers]
set -u
cd /data/TypeServer/bench/bot || exit 1

CONNS=${1:-10000}
RATE=${2:-10}
SECS=${3:-10}
WORKERS=${4:-1}
PORT=${PORT:-9001}
CFG=${CFG:-/data/TypeServer/demo/demo-echo/config/tsf.json}
BIN=${BIN:-/data/TypeServer/demo/demo-echo/demo_echo}

ulimit -n 65535 2>/dev/null

# 压测端不带 token 也不节流，所以两个开关都关（正确性由 m1-test 覆盖）
php85 -r '
$f = $argv[1];
$d = json_decode(file_get_contents($f), true);
$d["server"]["auth"]["require"] = false;
$d["server"]["rate_per_sec"] = 0;
$d["server"]["max_conn"] = 20000;
$d["server"]["worker_num"] = (int) $argv[2];
$d["server"]["reactor_num"] = (int) $argv[2];
$d["log"]["level"] = "warn";
file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
' "$CFG" "$WORKERS"

pkill -9 -x demo_echo 2>/dev/null
sleep 0.3
"$BIN" > /tmp/tsf_bot_server.log 2>&1 &

for _ in $(seq 1 100); do
  if ss -ltn 2>/dev/null | grep -q ":$PORT "; then break; fi
  sleep 0.05
done

HZ=$(getconf CLK_TCK)
# 多 worker 时 Swoole 会 fork 子进程，只读主进程 /proc 会漏掉大部分 CPU
read_cpu() {
  total=0
  for p in $(pgrep -x demo_echo 2>/dev/null); do
    v=$(awk '{print $14 + $15}' "/proc/$p/stat" 2>/dev/null)
    total=$((total + ${v:-0}))
  done
  echo "$total"
}

C0=$(read_cpu)
./bot 127.0.0.1 "$PORT" "$CONNS" "$RATE" "$SECS" "${WINDOW:-32}"
C1=$(read_cpu)

pkill -9 -x demo_echo 2>/dev/null

php85 -r '
$c0 = (float) $argv[1]; $c1 = (float) $argv[2]; $hz = (float) $argv[3];
$dt = (float) $argv[4]; $w = (int) $argv[5];
$cpu = ($c1 - $c0) / $hz;
printf("服务端 CPU: %.2f 核秒 / %.1f 秒 = %.1f%% 单核（%d worker，平均 %.1f%%/worker）\n",
    $cpu, $dt, $cpu / $dt * 100, $w, $cpu / $dt * 100 / $w);
' "$C0" "$C1" "$HZ" "$SECS" "$WORKERS"
