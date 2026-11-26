#!/bin/bash
# TLS 守卫：配置了证书就走 TLS / WSS，明文连接必须被拒；没配证书时行为不变。
#
# 为什么进回归：1.0 之前网关只有裸 TCP / 明文 WebSocket。对直连客户端的部署来说
# 这等于把协议帧明文放在公网上。Swoole 本身支持 TLS，缺的只是一个带校验的接入口。
#
# 四个场景（证书是脚本里现生成的自签证书，只用于本机回环）：
#   1. TCP + TLS：ssl:// 回显正常，tcp:// 拿不到回显
#   2. WSS：     TLS 之上的 WebSocket 升级 + 二进制帧回显，明文 ws 升级失败
#   3. 不配证书：行为和以前一样（ssl:// 连不上，tcp:// 正常）
#   4. 只配证书不配私钥：按明文启动并记 error——不能静默
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
BIN=./tls_guard
CERT=/tmp/tsf_tls_guard.crt
KEY=/tmp/tsf_tls_guard.key
SRV_LOG=/tmp/tls_guard_srv.log
CL_OUT=/tmp/tls_guard_client.out

for p in 9420 9421; do
    if ss -ltn 2>/dev/null | grep -q ":$p "; then
        echo "FAIL 端口 $p 已被占用（可能有别的套件在跑）"
        exit 1
    fi
done
if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/tls_guard_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/tls_guard_build.log）"
    tail -5 /tmp/tls_guard_build.log
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

# 自签证书，只对 127.0.0.1 / localhost 有效。不校验有效期之外的东西。
if ! openssl req -x509 -newkey rsa:2048 -nodes -keyout "$KEY" -out "$CERT" -days 2 \
        -subj "/CN=127.0.0.1" -addext "subjectAltName=IP:127.0.0.1,DNS:localhost" > /dev/null 2>&1; then
    echo "FAIL 生成自签证书失败（需要 openssl）"
    exit 1
fi

ok()  { PASS=$((PASS + 1)); echo "  PASS  $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }

# start_server <mode> <port> <ssl:1|0> <half:0|1>
start_server() {
    pkill -x tls_guard 2>/dev/null
    sleep 0.3
    TSF_MODE="$1" TSF_PORT="$2" TSF_SSL="$3" TSF_HALF="$4" TSF_CERT="$CERT" TSF_KEY="$KEY" \
        "$BIN" > "$SRV_LOG" 2>&1 &
    SRV=$!
    for _ in $(seq 1 50); do
        ss -ltn 2>/dev/null | grep -q ":$2 " && break
        sleep 0.1
    done
    sleep 0.2
}
stop_server() {
    kill "$SRV" 2>/dev/null
    wait "$SRV" 2>/dev/null
}
run_client() {   # <mode> <port>
    php85 client.php "$1" "$2" > "$CL_OUT" 2>&1
    CLIENT_RC=$?
    sed -n 's/^\(.*\): OK  .*$/  PASS  \1/p; s/^\(.*\): FAIL  .*$/  FAIL  \1/p' "$CL_OUT"
    # 客户端的 FAIL 必须计入总数：握手失败时客户端只打一行 "TLS 连接: FAIL"，
    # 不计数的话套件会以 0 失败判绿
    local cf cp
    cf=$(grep -c ': FAIL  ' "$CL_OUT")
    cp=$(grep -c ': OK  ' "$CL_OUT")
    FAIL=$((FAIL + cf))
    PASS=$((PASS + cp))
}

pkill -x tls_guard 2>/dev/null
PASS=0
FAIL=0

echo "== 1. TCP + TLS =="
start_server tcp 9420 1 0
run_client tcp 9420
if grep -q '"msg":"tls enabled"' "$SRV_LOG"; then ok "服务端：日志记了 tls enabled"; else bad "服务端：没有 tls enabled 日志"; fi
if grep -q '"secure":true' "$SRV_LOG"; then ok "服务端：isSecure() 为 true"; else bad "服务端：isSecure() 不为 true"; fi
stop_server

echo "== 2. WSS =="
start_server ws 9421 1 0
run_client ws 9421
if grep -q '"msg":"tls enabled".*"proto":"ws"' "$SRV_LOG"; then ok "服务端：WSS 记了 tls enabled"; else bad "服务端：WSS 没有 tls enabled 日志"; fi
if ! grep -q 'PHP Fatal error\|Uncaught' "$SRV_LOG"; then ok "WSS 场景没有逃逸异常"; else bad "WSS 场景有逃逸异常"; fi
stop_server

echo "== 3. 不配证书：行为不变 =="
start_server tcp 9420 0 0
# 明文模式下 client 的两条断言语义相反：ssl:// 握手失败、tcp:// 能回显——
# 它们**都应该 FAIL**，这正说明它在测 TLS 而不是碰巧通过。
php85 client.php tcp 9420 > "$CL_OUT" 2>&1
sed -n 's/^\(.*\): FAIL  .*$/  PASS  明文模式下这条如预期失败 → \1/p' "$CL_OUT"
# 上面两行也是通过的断言（"如预期失败"），计入总数
PASS=$((PASS + $(grep -c ': FAIL  ' "$CL_OUT")))
if [ "$(grep -c ': OK  ' "$CL_OUT")" -eq 0 ] && [ "$(grep -c ': FAIL  ' "$CL_OUT")" -eq 2 ]; then
    ok "不配证书：ssl:// 连不上、tcp:// 能回显（TLS 没有被静默打开）"
else
    bad "不配证书时 client 的结果不符合预期"
    cat "$CL_OUT" | sed 's/^/        /'
fi
# 明文模式下：ssl:// 握手失败（预期）、tcp:// 应该能回显 —— client 的两条断言在明文模式下语义相反，
# 所以这里单独探测明文回显
PLAIN=$(php85 -r '
$f = pack("NnCC", 8 + 5, 1001, 0, 0) . "plain";
$s = @stream_socket_client("tcp://127.0.0.1:9420", $e, $m, 2);
if ($s === false) { echo "noconn"; exit; }
fwrite($s, $f); stream_set_timeout($s, 1, 0);
$r = fread($s, 64); echo $r === false || $r === "" ? "noreply" : substr($r, 8, 5);
')
if [ "$PLAIN" = "plain" ]; then ok "不配证书时明文回显正常（和以前一样）"; else bad "不配证书时明文回显坏了：$PLAIN"; fi
if grep -q '"secure":false' "$SRV_LOG"; then ok "服务端：isSecure() 为 false"; else bad "服务端：isSecure() 不是 false"; fi
stop_server

echo "== 4. 只配证书不配私钥：不能静默 =="
start_server tcp 9420 1 1
sleep 0.2
if grep -q 'ssl_cert_file 和 ssl_key_file 必须同时配置' "$SRV_LOG"; then ok "服务端：记了配置错误的 error"; else bad "服务端：配置错误没有留日志"; fi
if grep -q '"secure":false' "$SRV_LOG"; then ok "服务端：按明文启动（isSecure=false）"; else bad "服务端：半配置下 isSecure 不是 false"; fi
stop_server

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
