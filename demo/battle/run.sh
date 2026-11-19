#!/usr/bin/env bash
# 编译并启动 battle demo（默认 9002，GM 在 9701）。
#
#   ./run.sh              编译 + 启动
#   ./run.sh --no-build   直接启动（已编译过）
#
# 压测：php85 bench.php 9002 500 12 "" 5000 6
# GM ：curl -s -X POST http://127.0.0.1:9701/gm -H "X-GM-Token: local-dev-only" \
#            -d '{"cmd":"config.reload","args":{}}'
set -u
cd "$(dirname "$0")" || exit 1

pkill -9 -x battle 2>/dev/null
sleep 0.2

if [ "${1:-}" != "--no-build" ]; then
    php85 ../../tsf/bin/tsf build . || { echo "编译失败"; exit 1; }
fi

mkdir -p var/log
./battle > /tmp/battle_server.log 2>&1 &

for _ in $(seq 1 100); do
    if ss -ltn 2>/dev/null | grep -q ":9002 "; then
        echo "已启动  9002（游戏） / 9701（GM）"
        echo "  应用日志 var/log/app.log    stdout /tmp/battle_server.log"
        exit 0
    fi
    sleep 0.1
done

echo "启动超时，看 /tmp/battle_server.log"
exit 1
