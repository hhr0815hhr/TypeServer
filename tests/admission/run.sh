#!/bin/bash
# 连接准入：登录时限 / 登录失败处置 / 未登录流量按来源限流 / token 作废。
#
# 为什么进回归：此前未登录的连接可以靠一直发心跳永久占着一个名额；限流只按连接，
# 断开重连就是新的桶；token 一旦签发，过期之前没有任何办法作废。
#
#   token  段：TokenAuth 的作废 / 轮换 / 旧格式开关 + assemble() 的默认值（进程内，不起网络）
#   server 段：Gateway::assemble() 装配的网关，参数来自 config/tsf.json；客户端分 6 个场景
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
PORT=9422
BIN=./admission
SRV_LOG=/tmp/admission_srv.log
CL_OUT=/tmp/admission_client.out

if ss -ltn 2>/dev/null | grep -q ":$PORT "; then
    echo "FAIL 端口 $PORT 已被占用（可能有别的套件在跑）"
    exit 1
fi
if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/admission_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/admission_build.log）"
    tail -5 /tmp/admission_build.log
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

ok()  { PASS=$((PASS + 1)); echo "  PASS  $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }

pkill -x admission 2>/dev/null
PASS=0
FAIL=0

echo "== token =="
ADM_MODE=token "$BIN" > /tmp/admission_token.log 2>&1
RC=$?
grep '^  OK   \|^  FAIL ' /tmp/admission_token.log
P=$(grep -c '^  OK   ' /tmp/admission_token.log)
F=$(grep -c '^  FAIL ' /tmp/admission_token.log)
PASS=$((PASS + P))
FAIL=$((FAIL + F))
[ "$RC" -eq 0 ] || { echo "  FAIL  token 段异常退出 rc=$RC"; FAIL=$((FAIL + 1)); tail -3 /tmp/admission_token.log | sed 's/^/        /'; }

echo
echo "== server =="
ADM_MODE=server "$BIN" > "$SRV_LOG" 2>&1 &
SRV=$!
for _ in $(seq 1 50); do
    ss -ltn 2>/dev/null | grep -q ":$PORT " && break
    sleep 0.1
done
sleep 0.3

# 场景的顺序有讲究：peer-ban 会把 127.0.0.1 封掉，所以放在最后；after-ban 在封禁期满之后
for scene in login-ok fail-per-conn peer-rate login-timeout peer-ban; do
    echo "-- $scene"
    php85 client.php "$scene" > "$CL_OUT" 2>&1
    CLIENT_RC=$?
    sed 's/^/  /' "$CL_OUT"
    n_ok=$(grep -c ': OK' "$CL_OUT")
    n_fail=$(grep -c ': FAIL' "$CL_OUT")
    PASS=$((PASS + n_ok))
    FAIL=$((FAIL + n_fail))
    if [ "$CLIENT_RC" -ne 0 ] || [ "$n_ok" -eq 0 ]; then
        echo "  FAIL  客户端异常退出 rc=$CLIENT_RC"
        FAIL=$((FAIL + 1))
    fi
    # 等一个扫描周期，让服务端的清扫 / 计数落定
    sleep 1.2
done

echo "-- after-ban（封禁 3 秒，上面已经等了 1.2 秒，再等 2.5 秒）"
sleep 2.5
php85 client.php after-ban > "$CL_OUT" 2>&1
CLIENT_RC=$?
sed 's/^/  /' "$CL_OUT"
PASS=$((PASS + $(grep -c ': OK' "$CL_OUT")))
FAIL=$((FAIL + $(grep -c ': FAIL' "$CL_OUT")))
if [ "$CLIENT_RC" -ne 0 ]; then
    echo "  FAIL  客户端异常退出 rc=$CLIENT_RC"
    FAIL=$((FAIL + 1))
fi

sleep 0.5
echo "-- 服务端"
if grep -q '"msg":"login timeout, closing"' "$SRV_LOG"; then ok "登录时限触发过"; else bad "登录时限没有触发"; fi
if grep -q 'too many login failures on one connection' "$SRV_LOG"; then ok "单连接失败上限触发过"; else bad "单连接失败上限没有触发"; fi
if grep -q 'login failures from one peer, banning' "$SRV_LOG"; then ok "来源封禁触发过"; else bad "来源封禁没有触发"; fi
if ! grep -q 'PHP Fatal error\|Uncaught' "$SRV_LOG"; then ok "服务端没有逃逸异常"; else bad "服务端有逃逸异常"; fi
kill "$SRV" 2>/dev/null
wait "$SRV" 2>/dev/null

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
