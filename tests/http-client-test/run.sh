#!/bin/bash
# 出站 HTTP 客户端套件：自己起一个目标服务（php -S），构建后打过去。
#
# 端口 9633 是这套独占的（其它套件用 9411-9427）。
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
PHP_BIN=${PHP_BIN:-php85}
PORT=9633

if ! command -v "$PHP_BIN" > /dev/null 2>&1; then
    echo "SKIP 需要 $PHP_BIN"
    exit 77
fi

"$PHP_BIN" -S 127.0.0.1:$PORT server.php > /tmp/http_client_srv.log 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null' EXIT

# 等端口就绪（最多 3 秒）
for i in 1 2 3 4 5 6; do
    if "$PHP_BIN" -r 'exit(@stream_socket_client("tcp://127.0.0.1:9633", $e, $s, 0.2) === false ? 1 : 0);'; then
        break
    fi
    sleep 0.5
done

LOG=/tmp/http_client_test_build.log
if ! TSF_PHP=$PHP_BIN "$PHP_BIN" "$ROOT/tsf/bin/tsf" build . > "$LOG" 2>&1; then
    echo "FAIL 编译（日志 $LOG）"
    tail -5 "$LOG"
    exit 1
fi

./http_client_test
