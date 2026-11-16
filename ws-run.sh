#!/usr/bin/env bash
# 启动 WebSocket 传输的实例并测试，结束后恢复配置
set -u
cd /data/TypeServer/demo/demo-echo || exit 1
WSPORT=9010

patch_ws() {
  python3 - <<'PY'
import json
p = "config/tsf.json"
d = json.load(open(p))
d["server"]["transport"] = "ws"
d["server"]["port"] = 9010
json.dump(d, open(p, "w"), indent=4, ensure_ascii=False)
PY
}

pkill -9 -x demo_echo 2>/dev/null
sleep 0.5
patch_ws
./demo_echo > /tmp/ws_srv.log 2>&1 &
for _ in $(seq 1 60); do
  if (exec 3<>/dev/tcp/127.0.0.1/$WSPORT) 2>/dev/null; then exec 3<&-; break; fi
  sleep 0.1
done
sleep 0.5

echo "=== WebSocket 测试（端口 $WSPORT）==="
php85 /data/TypeServer/demo/ws-test.php $WSPORT
RC=$?

echo
echo "=== 服务端日志（节选）==="
grep -E "server listen|gateway ready" var/log/app.log | tail -2

pkill -9 -x demo_echo 2>/dev/null
php85 /data/TypeServer/demo/fix-config.php
exit $RC
