#!/bin/bash
# 一键起 mmo-mini：WebSocket 服务（9401）+ 静态页面（8081，避开斗地主的 8080）
set -u
cd "$(dirname "$0")"

PORT_WS=9401
PORT_WEB=8081

if ss -tln 2>/dev/null | grep -q ":$PORT_WS "; then
    echo "端口 $PORT_WS 已被占用（可能上一次的服务还在跑）"
    echo "  停掉：fuser -k $PORT_WS/tcp"
    exit 1
fi

if [ ! -x ./mmo_mini ]; then
    echo "== 编译服务端 =="
    tsf build . || exit 1
fi

mkdir -p var/log
echo "== 启动服务端 ws://127.0.0.1:$PORT_WS =="
./mmo_mini > var/log/server.out 2>&1 &
SERVER_PID=$!

sleep 2
if ! ss -tln 2>/dev/null | grep -q ":$PORT_WS "; then
    echo "服务端启动失败，日志尾部："
    tail -10 var/log/server.out
    exit 1
fi
echo "  PID=$SERVER_PID"

echo
echo "== 打开浏览器 =="
echo "  http://127.0.0.1:$PORT_WEB"
echo
echo "  多开几个标签页（token 换 tok-a/b/c/d），WASD 或方向键移动"
echo "  走近对方才看得见 —— 虚线框就是视野范围（3×3 格）"
echo
echo "  停止：Ctrl+C（会一并停掉服务端）"
echo

trap 'kill $SERVER_PID 2>/dev/null' EXIT INT TERM
cd web && python3 -m http.server "$PORT_WEB"
