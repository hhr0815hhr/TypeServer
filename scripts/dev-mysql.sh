#!/bin/bash
# 起一个本地 MySQL 给 db-* / mail 测试用（端口 52315，和用例里的 DSN 对齐）。
#
#   bash scripts/dev-mysql.sh          # 起（已存在则复用）
#   bash scripts/dev-mysql.sh stop     # 停
#   bash scripts/dev-mysql.sh reset    # 删掉重来（清掉分片库）
#
# 为什么用容器而不是要求本机装 MySQL：测试只需要"一个 8.0 的空库"，
# 而本机装 MySQL 会带来版本、字符集、认证插件一整套环境差异。容器把变量固定下来。
#
# 认证插件用 mysql_native_password：MySQL 8 默认 caching_sha2_password 在部分
# 客户端/驱动组合下握手失败，测试没必要踩这个坑。
#
# 端口只绑 127.0.0.1：这是一个弱口令（CHANGE_ME）的测试库，不该出现在局域网上。
# 早先写的是 `-p 52315:3306`（即 0.0.0.0），2026-11-25 改掉；用 reset 重建一次才生效。
set -u

NAME=tsf-mysql
PORT=52315
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
        -e MYSQL_ROOT_PASSWORD="$PASS" \
        -e MYSQL_DATABASE="$DB" \
        -p "127.0.0.1:$PORT:3306" \
        mysql:8.0 --default-authentication-plugin=mysql_native_password >/dev/null \
        && echo "已创建 $NAME（首次初始化约 60s）"
fi

printf '等待就绪'
for _ in $(seq 1 90); do
    # 必须走 TCP（-h127.0.0.1）：首次初始化时镜像会先起一个 skip-networking 的临时实例，
    # 随后重启成正式实例。默认的 ping 走 unix socket，在临时实例上就能通——
    # 于是脚本宣布"就绪"、测试冲进去连库，正撞上重启（实测：Error while reading greeting packet）。
    # 临时实例不监听 TCP，TCP 能连上才说明正式实例起来了。
    if docker exec "$NAME" mysqladmin ping -h127.0.0.1 -uroot -p"$PASS" --silent >/dev/null 2>&1; then
        echo " —— 就绪（127.0.0.1:$PORT，库 $DB）"
        exit 0
    fi
    printf '.'
    sleep 1
done

echo " —— 超时，看日志：docker logs $NAME"
exit 1
