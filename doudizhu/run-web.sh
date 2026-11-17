#!/bin/bash
# 一键起斗地主 demo：WebSocket 服务（9301）+ 静态页面（8080）。
#
# 为什么不直接让 tsf 服务托管页面：它是 WS 服务，不提供 HTTP 静态文件。
# 生产环境会由 nginx 之类托管前端，这里用 python 的 http.server 就够。
set -u
cd "$(dirname "$0")"

PORT_WS=9301
PORT_WEB=8080

if ss -tln 2>/dev/null | grep -q ":$PORT_WS "; then
    echo "端口 $PORT_WS 已被占用（可能上一次的服务还在跑）"
    echo "  停掉：fuser -k $PORT_WS/tcp"
    exit 1
fi

if [ ! -x ./doudizhu ]; then
    echo "== 编译服务端 =="
    tsf build . || exit 1
fi

mkdir -p var/log
echo "== 启动服务端 ws://127.0.0.1:$PORT_WS =="
./doudizhu > var/log/server.out 2>&1 &
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
echo "  开三个标签页，token 分别填 tok-a / tok-b / tok-c"
echo "  三个人都点「准备」后自动发牌"
echo
echo "  停止：Ctrl+C（会一并停掉服务端）"
echo

trap 'kill $SERVER_PID 2>/dev/null' EXIT INT TERM
cd web && python3 -m http.server "$PORT_WEB"
