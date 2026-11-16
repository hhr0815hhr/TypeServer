#!/usr/bin/env bash
# 背压验证：关掉限流（否则先被限流拦住），把发送失败阈值调小，灌数据不读回包
set -u
cd "$(dirname "$0")" || exit 1
patch() {
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
    cur[parts[-1]] = (v == "true") if v in ("true", "false") else (int(v) if v.isdigit() else v)
json.dump(d, open(p, "w"), indent=4, ensure_ascii=False)
PY
}

pkill -9 -x demo_echo 2>/dev/null
sleep 0.5
patch server.rate_per_sec=0 server.send_fail_limit=20
./demo_echo > /tmp/bp_srv.log 2>&1 &
for _ in $(seq 1 60); do
  if (exec 3<>/dev/tcp/127.0.0.1/9001) 2>/dev/null; then exec 3<&-; break; fi
  sleep 0.1
done
sleep 0.5

echo "=== 背压测试（rate=0, send_fail_limit=20）==="
php85 /data/TypeServer/demo/bp-test.php 9001 3000 4096

sleep 0.5
echo
echo "=== 服务端日志：慢消费者 ==="
if grep -q "slow consumer" var/log/app.log; then
  grep "slow consumer" var/log/app.log | tail -2
  echo "PASS 服务端识别并断开了慢客户端"
  RC=0
else
  echo "FAIL 未触发背压（可能缓冲区还没堆满）"
  grep -E "session close|idle" var/log/app.log | tail -3
  RC=1
fi

pkill -9 -x demo_echo 2>/dev/null
php85 /data/TypeServer/demo/fix-config.php
exit $RC
