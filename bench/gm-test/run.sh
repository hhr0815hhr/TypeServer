#!/usr/bin/env bash
# T6-05 回归：进程内 39 项 + 用真 curl 打一遍（HTTP 框架不能只有自己写的客户端能认）
set -u
cd /data/TypeServer/bench/gm-test || exit 1
BIN=./gm_test
TOKEN=s3cr3t-token-for-test
PORT=19703

pkill -9 -x gm_test 2>/dev/null
$BIN > /tmp/gm_unit.log 2>&1
RC=$?
echo "$(grep '结果:' /tmp/gm_unit.log | tail -1)  （进程内用例）"
grep -E "^  FAIL" /tmp/gm_unit.log | head -5

echo
echo "=== 真 curl 打一遍 ==="
$BIN serve 12 > /tmp/gm_serve.log 2>&1 &
for _ in $(seq 1 60); do
  grep -q "^ready" /tmp/gm_serve.log 2>/dev/null && break
  sleep 0.05
done
grep -m1 "^ready" /tmp/gm_serve.log

p() { printf "  %-34s → %s\n" "$1" "$2"; }

H=$(curl -s -o /tmp/gm_b1 -w '%{http_code}' http://127.0.0.1:$PORT/health)
p "GET /health" "$H $(cat /tmp/gm_b1)"

H=$(curl -s -o /tmp/gm_b2 -w '%{http_code}' -X POST http://127.0.0.1:$PORT/gm -d '{"cmd":"ping"}')
p "POST /gm 无 token" "$H $(cat /tmp/gm_b2)"

H=$(curl -s -o /tmp/gm_b3 -w '%{http_code}' -X POST http://127.0.0.1:$PORT/gm \
      -H "X-GM-Token: $TOKEN" -d '{"cmd":"ping"}')
p "POST /gm ping" "$H $(cat /tmp/gm_b3)"

H=$(curl -s -o /tmp/gm_b4 -w '%{http_code}' -X POST http://127.0.0.1:$PORT/gm \
      -H "X-GM-Token: $TOKEN" -d '{"cmd":"player.ban","args":{"uid":1001,"reason":"外挂"}}')
p "POST /gm player.ban" "$H $(cat /tmp/gm_b4)"

H=$(curl -s -o /tmp/gm_b5 -w '%{http_code}' -X POST http://127.0.0.1:$PORT/gm \
      -H "X-GM-Token: $TOKEN" -d '{"cmd":"player.ban","args":{"uid":1001}}')
p "POST /gm 缺 reason" "$H $(cat /tmp/gm_b5)"

# 中文 body：验证 Content-Length 按字节算，不是按字符
H=$(curl -s -o /tmp/gm_b6 -w '%{http_code}' -X POST http://127.0.0.1:$PORT/gm \
      -H "X-GM-Token: $TOKEN" -d '{"cmd":"player.ban","args":{"uid":2,"reason":"刷金"}}')
p "POST /gm 中文参数" "$H $(cat /tmp/gm_b6)"

echo
echo "=== 判定 ==="
ok=0; bad=0
chk() { if [ "$1" = "$2" ]; then ok=$((ok+1)); else bad=$((bad+1)); echo "  FAIL $3 (got=$1 want=$2)"; fi; }
chk "$(cat /tmp/gm_b1)" '{"ok":true,"data":{"up":true}}' "health body"
chk "$(cat /tmp/gm_b3)" '{"ok":true,"data":{"pong":true}}' "ping body"
chk "$(grep -c '"ok":true' /tmp/gm_b4)" '1' "ban ok"
chk "$(grep -c '外挂' /tmp/gm_b4)" '1' "ban 回显 reason"
chk "$(grep -c '刷金' /tmp/gm_b6)" '1' "中文参数没被截断"
echo "  通过 $ok / 失败 $bad"

echo
echo "--- 审计日志（真 curl 产生的，在 /tmp/gm_test.log）---"
grep -o '"msg":"gm: [^"]*","ctx":{[^}]*}' /tmp/gm_test.log | head -4

wait 2>/dev/null
pkill -9 -x gm_test 2>/dev/null
[ "$RC" -eq 0 ] && [ "$bad" -eq 0 ] && exit 0 || exit 1
