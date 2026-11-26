#!/bin/bash
# 积压续处理：一条连接一次灌很多帧，服务端必须自己把积压消化完。
#
# 为什么进回归：Server::feed() 每次最多处理 1000 帧就 break（防饿死其它连接），
# 修之前剩下的帧要等这条连接**下一次发来任何字节**才会被处理。
# 实测（2026-11-25）：一次发 3000 帧只回 2002，之后再发 1 帧才多回 999。
# 不崩、不报错，客户端那边就是"请求超时"。
#
# 用法：run.sh [帧数]，默认跑 3000（3 轮）和 12000（12 轮）两档。
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
PORT=9414
BIN=./feed_resume
SRV_LOG=/tmp/feed_resume_srv.log
CL_OUT=/tmp/feed_resume_client.out

if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/feed_resume_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/feed_resume_build.log）"
    tail -5 /tmp/feed_resume_build.log
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi
if ss -ltn 2>/dev/null | grep -q ":$PORT "; then
    echo "FAIL 端口 $PORT 已被占用（可能有别的套件在跑）"
    exit 1
fi

pkill -x feed_resume 2>/dev/null
sleep 0.3
"$BIN" > "$SRV_LOG" 2>&1 &
SRV=$!
for _ in $(seq 1 50); do
    ss -ltn 2>/dev/null | grep -q ":$PORT " && break
    sleep 0.1
done

TOTAL_P=0
TOTAL_F=0
if [ $# -ge 1 ]; then
    SIZES="$*"
else
    SIZES="3000 12000"
fi

for n in $SIZES; do
    php85 client.php "$n" > "$CL_OUT" 2>&1
    RC=$?
    echo "== 一次 write $n 帧 =="
    sed -n 's/^\(.*\): OK  .*$/  PASS  \1/p; s/^\(.*\): FAIL  .*$/  FAIL  \1/p' "$CL_OUT"
    grep -v ': OK  \|: FAIL  ' "$CL_OUT" | head -5
    P=$(grep -c ': OK  ' "$CL_OUT")
    F=$(grep -c ': FAIL  ' "$CL_OUT")
    if [ "$RC" -ne 0 ]; then
        echo "  FAIL  客户端异常退出 rc=$RC"
        F=$((F + 1))
    fi
    echo "  => passed=$P failed=$F"
    echo
    TOTAL_P=$((TOTAL_P + P))
    TOTAL_F=$((TOTAL_F + F))
done

sleep 0.3
kill "$SRV" 2>/dev/null
wait "$SRV" 2>/dev/null

# 续处理靠 Swoole 定时器回调。回调签名写错时每轮都会抛 ArgumentCountError，
# 而客户端那边可能还是全收到了——所以服务端日志必须单独查。
if grep -q 'PHP Fatal error\|Uncaught\|ArgumentCountError\|PHP Warning' "$SRV_LOG"; then
    echo "  FAIL  服务端：日志里有异常/警告（续处理回调出错）"
    grep 'PHP Fatal error\|Uncaught\|ArgumentCountError\|PHP Warning' "$SRV_LOG" | head -3 | sed 's/^/        /'
    TOTAL_F=$((TOTAL_F + 1))
else
    echo "  PASS  服务端：日志干净，没有定时器回调异常"
    TOTAL_P=$((TOTAL_P + 1))
fi
if [ "$(grep -c '"msg":"worker start"' "$SRV_LOG")" -eq 1 ]; then
    echo "  PASS  服务端：进程存活（worker start 1 次）"
    TOTAL_P=$((TOTAL_P + 1))
else
    echo "  FAIL  服务端：worker 启动次数异常"
    TOTAL_F=$((TOTAL_F + 1))
fi

echo
echo "passed=$TOTAL_P failed=$TOTAL_F"
[ "$TOTAL_F" -eq 0 ] && exit 0 || exit 1
