#!/bin/bash
# 集群守卫：集群通道被坏包打穿后，节点还转不转、坏链路会不会变成黑洞。
#
# 为什么进回归：修之前（2026-11-25 实测）一个 18 字节的坏头堵在读缓冲队首，
# 之后 25.6 万个合法包**一个都没被处理**，而 hasPeer() 仍是 true、send() 仍返回 true；
# 读缓冲无界增长；每来一次新数据就往 stderr 刷一行 PHP Fatal error（2 秒 1028 行）。
# 集群对端是"自己人"，所以这条路径平时碰不到——碰到的表现是"跨服消息偶发全丢"。
#
# 用法：run.sh [json|len]，默认两种都跑。
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
BIN=./cluster_guard
SRV_OUT=/tmp/cluster_guard_srv.out
SRV_ERR=/tmp/cluster_guard_srv.err
CL_OUT=/tmp/cluster_guard_client.out

if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/cluster_guard_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/cluster_guard_build.log）"
    tail -5 /tmp/cluster_guard_build.log
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi
if ss -ltn 2>/dev/null | grep -q ':9412 \|:9413 '; then
    echo "FAIL 端口 9412/9413 已被占用（可能有别的套件在跑）"
    exit 1
fi

run_mode() {
    MODE="$1"
    pkill -x cluster_guard 2>/dev/null
    sleep 0.3
    "$BIN" > "$SRV_OUT" 2> "$SRV_ERR" &
    SRV=$!
    for _ in $(seq 1 50); do
        ss -ltn 2>/dev/null | grep -q ':9412 ' && break
        sleep 0.1
    done
    sleep 0.3
    T0=$(grep -c '^TICK' "$SRV_ERR")

    php85 client.php "$MODE" > "$CL_OUT" 2>&1
    CLIENT_RC=$?

    # 客户端结束后再留 1 秒的 TICK：坏包之后调度器必须还在走
    sleep 1.0
    T1=$(grep -c '^TICK' "$SRV_ERR")
    kill "$SRV" 2>/dev/null
    wait "$SRV" 2>/dev/null

    echo "== 坏包类型: $MODE =="
    sed -n 's/^\(.*\): OK  .*$/  PASS  \1/p; s/^\(.*\): FAIL  .*$/  FAIL  \1/p' "$CL_OUT"
    grep -v ': OK  \|: FAIL  ' "$CL_OUT" | head -5
    P=$(grep -c ': OK  ' "$CL_OUT")
    F=$(grep -c ': FAIL  ' "$CL_OUT")

    # 最关键的一条：不能再有异常穿到 Swoole。修之前这里每来一次新数据就一行。
    if grep -q 'PHP Fatal error\|Uncaught' "$SRV_ERR" "$SRV_OUT"; then
        echo "  FAIL  服务端：有异常穿到 Swoole"
        grep -h 'PHP Fatal error\|Uncaught' "$SRV_ERR" "$SRV_OUT" | head -2 | sed 's/^/        /'
        F=$((F + 1))
    else
        echo "  PASS  服务端：没有异常穿到 Swoole"
        P=$((P + 1))
    fi

    # 丢弃链路必须留痕：一行带 side / node / err 的 error。恰好一行——
    # 0 行说明没丢弃（黑洞），多行说明同一件事被重复报了。
    D=$(grep -c '"msg":"cluster: dropping link on bad data"' "$SRV_OUT")
    if [ "$D" -eq 1 ]; then
        echo "  PASS  服务端：丢弃链路记了恰好一行 error（$(grep -o '"err":"[^"]*"' "$SRV_OUT" | head -1)）"
        P=$((P + 1))
    else
        echo "  FAIL  服务端：dropping link 日志 $D 行（期望 1）"
        F=$((F + 1))
    fi

    # 调度器还在走：TICK 是每 200ms 一行，客户端跑了 2 秒多，至少要多出 5 行
    if [ $((T1 - T0)) -ge 5 ]; then
        echo "  PASS  服务端：Scheduler 继续运转（TICK $T0 → $T1）"
        P=$((P + 1))
    else
        echo "  FAIL  服务端：TICK 停了（$T0 → $T1）"
        F=$((F + 1))
    fi

    STARTS=$(grep -c '"msg":"worker start"' "$SRV_OUT")
    # 只数 worker 自己的 pid（理由同 gw-guard：`server shutdown` 是 master 进程打的，
    # 走优雅停机之后才出现；算进来会把"同一个 worker"误判成两个进程）
    PIDS=$(grep '"msg":"worker start"' "$SRV_OUT" | grep -o '"pid":[0-9]*' | sort -u | wc -l)
    if [ "$STARTS" -eq 1 ] && [ "$PIDS" -eq 1 ]; then
        echo "  PASS  服务端：进程存活（worker start 1 次、同一个 worker pid）"
        P=$((P + 1))
    else
        echo "  FAIL  服务端：worker start=$STARTS 次、worker pid=$PIDS 个"
        F=$((F + 1))
    fi

    if [ "$CLIENT_RC" -ne 0 ]; then
        echo "  FAIL  客户端异常退出 rc=$CLIENT_RC"
        F=$((F + 1))
    fi
    echo "  => passed=$P failed=$F"
    echo
    TOTAL_P=$((TOTAL_P + P))
    TOTAL_F=$((TOTAL_F + F))
}

TOTAL_P=0
TOTAL_F=0
if [ $# -ge 1 ]; then
    run_mode "$1"
else
    run_mode json
    run_mode len
fi

pkill -x cluster_guard 2>/dev/null
echo "passed=$TOTAL_P failed=$TOTAL_F"
[ "$TOTAL_F" -eq 0 ] && exit 0 || exit 1
