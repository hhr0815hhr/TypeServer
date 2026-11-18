#!/usr/bin/env bash
# 协议切换验证：同一份二进制，只改 protocol.payload，跑 raw / json 两组
set -u
cd "$(dirname "$0")" || exit 1
FAIL=0

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
    cur[parts[-1]] = v
json.dump(d, open(p, "w"), indent=4, ensure_ascii=False)
PY
}

restart() {
  pkill -9 -x demo_echo 2>/dev/null
  sleep 0.4
  ./demo_echo > /tmp/proto_srv.log 2>&1 &
  for _ in $(seq 1 60); do
    if (exec 3<>/dev/tcp/127.0.0.1/9001) 2>/dev/null; then exec 3<&-; break; fi
    sleep 0.1
  done
  sleep 0.4
}

for MODE in raw json; do
  echo "=== payload=$MODE ==="
  patch protocol.frame=native protocol.payload=$MODE
  restart
  php85 $(cd "$(dirname "$0")/.." && pwd)/demo/proto-test.php 9001 $MODE || FAIL=1
  echo
done

echo "=== 服务端日志里的协议声明 ==="
grep '"msg":"protocol"' var/log/app.log | tail -2

pkill -9 -x demo_echo 2>/dev/null
php85 $(cd "$(dirname "$0")/.." && pwd)/demo/fix-config.php
exit $FAIL
