#!/bin/bash
# 起游戏服 + 静态服务器（Web 前端）。
#
# 两个进程：
#   9061  WebSocket 游戏服（AOT 产物 ddz_hall）
#   8080  静态文件（web/index.html）
#
# 为什么不合成一个：Swoole 的 WebSocket Server 这边没有接 HTTP 静态文件
# （框架的 Transport 接口没暴露 onRequest），所以页面另外托管。
# 前端里 WS_PORT 写死 9061，就是为了跨过这个端口差。
set -u
cd "$(dirname "$0")" || exit 1

if [ ! -x ./ddz_hall ]; then
    echo "还没有编译，先跑：tsf build ."
    exit 1
fi

echo "游戏服 WebSocket : 9061"
echo "页面             : http://127.0.0.1:8080/"
echo "开三个浏览器窗口，分别用 tok-a / tok-b / tok-c 登录"
echo

python3 -m http.server 8080 --directory web > /tmp/ddz-web.log 2>&1 &
WEB_PID=$!
trap 'kill $WEB_PID 2>/dev/null' EXIT INT TERM

./ddz_hall
