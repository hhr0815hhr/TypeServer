#!/bin/bash
# pkg/http 的回归测试。
#
# 按 swoole-contract 的三条规矩来：
#   1. 独立端口，不复用
#   2. 按 PID 回收，不用 pkill -f
#   3. 每轮先确认端口空闲
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"
PORT=19701
SRV_PID=0

PASS=0
FAIL=0
ok()  { PASS=$((PASS + 1)); printf 'PASS %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf 'FAIL %s  (%s)\n' "$1" "${2:-}"; }

cleanup() {
    if [ "$SRV_PID" -gt 0 ]; then
        kill -9 "$SRV_PID" 2>/dev/null
        wait "$SRV_PID" 2>/dev/null
        SRV_PID=0
    fi
}
trap cleanup EXIT

# 编译
if ! "$TSF" build . > /tmp/http_test_build.log 2>&1; then
    bad "编译" "日志 /tmp/http_test_build.log"
    exit 1
fi

# 找产物
OUT=""
for f in *; do
    if [ -x "$f" ] && [ "$f" != "run.sh" ]; then OUT="$f"; fi
done
[ -z "$OUT" ] && { bad "没找到产物"; exit 1; }

# 确认端口空闲
for i in $(seq 1 30); do
    ss -ltn 2>/dev/null | grep -q ":$PORT " || break
    sleep 0.1
done

"./$OUT" > /tmp/http_test_srv.log 2>&1 &
SRV_PID=$!

# 等服务起来
UP=0
for i in $(seq 1 50); do
    if curl -s -o /dev/null --max-time 1 "http://127.0.0.1:$PORT/hello/x"; then
        UP=1
        break
    fi
    sleep 0.2
done
[ "$UP" -eq 0 ] && { bad "服务没起来" "$(tail -3 /tmp/http_test_srv.log)"; exit 1; }

B="http://127.0.0.1:$PORT"

# ---- 1. 路径参数 ----
BODY=$(curl -s --max-time 3 "$B/hello/alice")
echo "$BODY" | grep -q '"name":"alice"' && ok "路径参数 /hello/alice" || bad "路径参数" "$BODY"

# ---- 2. 404 ----
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$B/nope")
[ "$CODE" = "404" ] && ok "未知路径 404" || bad "未知路径" "得到 $CODE"

# ---- 3. 同路径不同方法（当前实现返回 404，未做 405；记在 ROADMAP 里）----
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 -X DELETE "$B/hello/alice")
[ "$CODE" = "404" ] && ok "同路径不同方法 404（405 未实现）" || bad "同路径不同方法" "得到 $CODE"

# ---- 4. CORS 预检 ----
HEADERS=$(curl -s -D - -o /dev/null --max-time 3 -X OPTIONS "$B/hello/alice" \
    -H 'Origin: http://example.com' -H 'Access-Control-Request-Method: GET')
echo "$HEADERS" | head -1 | grep -q '204' && ok "预检 204" || bad "预检" "$(echo "$HEADERS" | head -1)"
echo "$HEADERS" | grep -qi 'access-control-allow-origin' && ok "预检带 CORS 头" || bad "预检 CORS 头"

# ---- 5. 正常响应也带 CORS 头 ----
H2=$(curl -s -D - -o /dev/null --max-time 3 "$B/hello/bob" -H 'Origin: http://example.com')
echo "$H2" | grep -qi 'access-control-allow-origin' && ok "正常响应带 CORS 头" || bad "正常响应 CORS 头"

# ---- 6. 401 ----
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$B/private")
[ "$CODE" = "401" ] && ok "未授权 401" || bad "未授权" "得到 $CODE"

# ---- 7. 业务码与 HTTP 状态分离 ----
BODY=$(curl -s --max-time 3 "$B/private")
echo "$BODY" | grep -q '"code":40100' && ok "业务码 40100 与 HTTP 401 分离" || bad "业务码" "$BODY"

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
