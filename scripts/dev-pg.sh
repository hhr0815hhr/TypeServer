#!/bin/bash
# 起一个本地 PostgreSQL 给 db-pg-test 用（端口 5432，和用例里的 DSN 对齐）。
#
#   bash scripts/dev-pg.sh          # 起（已存在则复用）
#   bash scripts/dev-pg.sh stop     # 停
#   bash scripts/dev-pg.sh reset    # 删掉重来
#
# 只绑 127.0.0.1：弱口令的测试库，不该出现在局域网上。
# 就绪探测走 TCP 而不是 pg_isready 的默认 socket：镜像初始化时会先起一个
# 只听 socket 的临时实例，用 socket 探会假阳性（同 dev-mysql.sh 踩过的坑）。
set -u

NAME=tsf-pg
PORT=5432
USER_NAME=tsf
PASS=CHANGE_ME
DB=tsf

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
    docker run -d --name "$NAME" \
        -e POSTGRES_USER="$USER_NAME" \
        -e POSTGRES_PASSWORD="$PASS" \
        -e POSTGRES_DB="$DB" \
        -p "127.0.0.1:$PORT:5432" \
        postgres:16-alpine >/dev/null \
        && echo "已创建 $NAME"
fi

printf '等待就绪'
for _ in $(seq 1 90); do
    if docker exec "$NAME" pg_isready -h 127.0.0.1 -U "$USER_NAME" -d "$DB" >/dev/null 2>&1; then
        echo " —— 就绪（127.0.0.1:$PORT，库 $DB）"
        exit 0
    fi
    printf '.'
    sleep 1
done

echo " —— 超时，看日志：docker logs $NAME"
exit 1
