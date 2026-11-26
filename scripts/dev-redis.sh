#!/bin/bash
# 起一个本地 Redis 给 registry / redis 测试用（端口 6379，和用例里的地址对齐）。
#
#   bash scripts/dev-redis.sh          # 起（已存在则复用）
#   bash scripts/dev-redis.sh stop     # 停
#   bash scripts/dev-redis.sh reset    # 删掉重来
#
# 为什么用容器：和 dev-mysql.sh 同理——测试只需要"一个空的 Redis"，
# 本机装会带来版本与持久化配置的差异。容器把变量固定下来。
#
# 只绑 127.0.0.1：测试会往里写数据，不该暴露在局域网上。
set -u

NAME=tsf-redis
PORT=6379

case "${1:-up}" in
    stop)
        docker rm -f "$NAME" >/dev/null 2>&1 && echo "已停止 $NAME" || echo "$NAME 不存在"
        exit 0
        ;;
    reset)
        docker rm -f "$NAME" >/dev/null 2>&1
        echo "已删除 $NAME（下次 up 会重建）"
        exit 0
        ;;
esac

if docker ps -a --format '{{.Names}}' | grep -qx "$NAME"; then
    if docker ps --format '{{.Names}}' | grep -qx "$NAME"; then
        echo "$NAME 已在运行"
    else
        docker start "$NAME" >/dev/null && echo "已启动已有的 $NAME"
    fi
else
    docker run -d --name "$NAME" -p "127.0.0.1:$PORT:6379" redis:7-alpine >/dev/null \
        && echo "已创建 $NAME"
fi

printf '等待就绪'
for _ in $(seq 1 30); do
    if docker exec "$NAME" redis-cli ping 2>/dev/null | grep -q PONG; then
        echo " —— 就绪（127.0.0.1:$PORT）"
        exit 0
    fi
    printf '.'
    sleep 1
done

echo " —— 超时，看日志：docker logs $NAME"
exit 1
