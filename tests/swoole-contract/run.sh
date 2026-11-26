#!/bin/bash
# Swoole 契约测试：验证框架对扩展的四组假设。
#
# 上一版失败在脚手架：端口复用 + pkill -f 匹配到自己。这一版的三条规矩：
#   1. 每个 case 用**独立端口**，绝不复用（残留进程就影响不到下一轮）
#   2. 服务端按 **PID** 回收（kill $SRV_PID），不用 pkill -f
#   3. 每个 case 先把上一轮清干净并确认端口空闲，再开始
set -u
cd "$(dirname "$0")" || exit 1

PASS=0
FAIL=0
ok()  { PASS=$((PASS + 1)); printf 'PASS %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf 'FAIL %s\n' "$1"; }

PORT=19500
SRV_PID=0

kill_srv() {
    if [ "$SRV_PID" -gt 0 ]; then
        kill -9 "$SRV_PID" 2>/dev/null
        wait "$SRV_PID" 2>/dev/null
        SRV_PID=0
    fi
    sleep 0.3
}

port_free() {
    local port="$1" i
    for i in $(seq 1 40); do
        if ! ss -ltn 2>/dev/null | grep -q ":$port "; then
            return 0
        fi
        sleep 0.1
    done
    return 1
}

start_srv() {   # start_srv <args...>
    SRV_PID_FILE=/tmp/sc_srv_$PORT.log
    php85 main.php "$@" > "$SRV_PID_FILE" 2>&1 &
    SRV_PID=$!
    local i
    for i in $(seq 1 50); do
        ss -ltn 2>/dev/null | grep -q ":$PORT " && return 0
        sleep 0.1
    done
    return 1
}

next_port() { PORT=$((PORT + 1)); }

trap 'kill_srv' EXIT

echo "=== 1. 单帧大小 vs buffer_output_size(64KB) ==="
for BODY in 1024 65536 131072 1048576; do
    next_port
    kill_srv
    if ! port_free "$PORT"; then bad "端口 $PORT 不空闲"; continue; fi
    if ! start_srv srv-big "$PORT" "$BODY"; then
        bad "body=$BODY 服务没起来：$(tail -2 /tmp/sc_srv_$PORT.log)"
        continue
    fi
    OUT=$(php85 main.php cli-big "$PORT" "$BODY" 2>&1 | tail -1)
    SRV=$(tail -2 /tmp/sc_srv_$PORT.log | head -1)
    kill_srv
    if echo "$OUT" | grep -q "intact=yes"; then
        ok "body=$BODY 完整送达（$SRV）"
    else
        bad "body=$BODY 没送达：$OUT | 服务端: $SRV"
    fi
done

echo
echo "=== 2. 慢客户端（不读）时 send() 的行为 ==="
next_port
kill_srv
if start_srv srv-slow "$PORT" 4000; then
    php85 main.php cli-slow "$PORT" 4 > /dev/null 2>&1 &
    CLI=$!
    for _ in $(seq 1 60); do
        grep -q SLOW_RESULT /tmp/sc_srv_$PORT.log 2>/dev/null && break
        sleep 0.2
    done
    RES=$(grep -o "SLOW_RESULT.*" /tmp/sc_srv_$PORT.log | head -1)
    wait $CLI 2>/dev/null
    kill_srv
    if [ -z "$RES" ]; then
        bad "慢客户端没有结果输出"
    else
        ok "$RES"
    fi
else
    bad "慢客户端服务没起来"
fi

echo
echo "=== 3. shutdown() 是否送达 shutdown 前 send 的最后一帧 ==="
next_port
kill_srv
if start_srv srv-shutdown "$PORT"; then
    OUT=$(php85 main.php cli-shutdown "$PORT" 2>&1 | tail -1)
    SRV=$(grep -o "SENT_FINAL.*" /tmp/sc_srv_$PORT.log | head -1)
    kill_srv
    if echo "$OUT" | grep -q "final=yes"; then
        ok "最后一帧送达（$OUT / $SRV）"
    else
        bad "最后一帧丢了：$OUT / $SRV"
    fi
else
    bad "shutdown 服务没起来"
fi

echo
echo "=== 4. Timer::after / Timer::tick 的回调签名 ==="
# 这一组不需要端口，也不起服务端：每个子模式一个独立进程。
# after-arg 那个**本来就应该崩**——崩了才说明"after 不传参数"这条契约成立。
OUT=$(php85 main.php timer after-noarg 2>&1)
if [ "$OUT" = "FIRED" ]; then ok "after 的无参回调触发"; else bad "after 无参回调没触发：$OUT"; fi

OUT=$(php85 main.php timer after-arg 2>&1)
RC=$?
if [ "$RC" -ne 0 ] && echo "$OUT" | grep -q "ArgumentCountError"; then
    ok "after 的回调不传参数（声明形参 → ArgumentCountError，rc=$RC）"
else
    bad "after 带参回调居然没报错：rc=$RC $OUT"
fi

OUT=$(php85 main.php timer after-zero 2>&1)
if echo "$OUT" | grep -q "RET=false" && ! echo "$OUT" | grep -q ZERO_FIRED; then
    ok "after(0) 不合法：返回 false、不触发"
else
    bad "after(0) 的行为变了：$OUT"
fi

OUT=$(php85 main.php timer tick-arg 2>&1)
if echo "$OUT" | grep -q "TICKS=3" && echo "$OUT" | grep -q "TICK id=[1-9]"; then
    ok "tick 的回调收到定时器 id（触发 3 次）"
else
    bad "tick 带参回调异常：$OUT"
fi

echo
echo "=== 判定 ==="
echo "PASS $PASS 项 / FAIL $FAIL 项"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
