#!/usr/bin/env bash
# M1 验证：配不同参数起服务，跑四个用例，最后复原配置
set -u
cd "$(dirname "$0")" || exit 1
SRV=./demo_echo
TEST="php85 /data/TypeServer/demo/m1-test.php"
CFG=config/tsf.json

patch() { # key=value ...
  python3 - "$@" <<'PY'
import json, sys
p = "config/tsf.json"
d = json.load(open(p))
for kv in sys.argv[1:]:
    k, v = kv.split("=", 1)
    parts = k.split(".")
    cur = d
    for x in parts[:-1]:
        cur = cur.setdefault(x, {})
    if v in ("true", "false"):
        cur[parts[-1]] = (v == "true")
    elif v.isdigit():
        cur[parts[-1]] = int(v)
    else:
        cur[parts[-1]] = v
json.dump(d, open(p, "w"), indent=4, ensure_ascii=False)
PY
}

restart() {
  pkill -9 -x demo_echo 2>/dev/null
  for _ in $(seq 1 40); do
    if ! (exec 3<>/dev/tcp/127.0.0.1/9001) 2>/dev/null; then break; fi
    exec 3<&- 2>/dev/null; sleep 0.1
  done
  sleep 0.3
  "$SRV" > /tmp/m1_srv.log 2>&1 &
  for _ in $(seq 1 60); do
    if (exec 3<>/dev/tcp/127.0.0.1/9001) 2>/dev/null; then exec 3<&-; break; fi
    sleep 0.1
  done
  sleep 0.4
}

FAIL=0

echo "=== 1) 鉴权 / 顶号（heartbeat=30, rate=50/100）==="
patch server.auth.require=true server.heartbeat_sec=30 server.rate_per_sec=50 server.rate_burst=100
restart
$TEST 9001 auth || FAIL=1
$TEST 9001 kick || FAIL=1

echo
echo "=== 2) 心跳（阈值调成 3s）==="
patch server.heartbeat_sec=3
restart
$TEST 9001 heartbeat 3 || FAIL=1

echo
echo "=== 3) 限流（rate=20/s, burst=100）==="
patch server.heartbeat_sec=30 server.rate_per_sec=20 server.rate_burst=100
restart
$TEST 9001 rate || FAIL=1

echo
echo "=== 复原配置 ==="
patch server.heartbeat_sec=30 server.rate_per_sec=50 server.rate_burst=100
pkill -9 -x demo_echo 2>/dev/null
echo "done"

if [ "$FAIL" -ne 0 ]; then
  echo "有失败用例"
  exit 1
fi
