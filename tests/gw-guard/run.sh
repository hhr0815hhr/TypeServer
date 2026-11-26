#!/bin/bash
# 网关守卫：业务 handler 抛异常时，影响面必须止于"这一帧"。
#
# 为什么进回归：Gateway 的 handler 直接跑在 Swoole 收包回调里，是唯一人人都能打到的入口。
# 修之前（2026-11-25 实测）一个 throw 会让同一次收包里后面的帧**静默丢失**，
# 并且每抛一次往 stderr 刷一行 PHP Fatal error（不经 Logger、不受级别控制、无计数）。
# 这种问题不会让进程挂、不会有告警，只是"偶发丢请求"——所以必须有断言盯着。
#
# 客户端断言 + 服务端日志断言，两边都查才判通过。
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
PORT=9411
BIN=./gw_guard
SRV_LOG=/tmp/gw_guard_srv.out
SRV_ERR=/tmp/gw_guard_srv.err

if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/gw_guard_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/gw_guard_build.log）"
    tail -5 /tmp/gw_guard_build.log
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

pkill -x gw_guard 2>/dev/null
sleep 0.3
if ss -ltn 2>/dev/null | grep -q ":$PORT "; then
    echo "FAIL 端口 $PORT 已被占用（可能有别的套件在跑）"
    exit 1
fi

"$BIN" > "$SRV_LOG" 2> "$SRV_ERR" &
SRV=$!
for _ in $(seq 1 50); do
    ss -ltn 2>/dev/null | grep -q ":$PORT " && break
    sleep 0.1
done

php85 client.php "$PORT" > /tmp/gw_guard_client.out 2>&1
CLIENT_RC=$?
# 客户端按最长 0.6s 的窗口收包，这里留一小段让最后几帧的日志落盘
sleep 0.3

kill "$SRV" 2>/dev/null
wait "$SRV" 2>/dev/null

echo "  --- 客户端 ---"
sed -n 's/^\(.*\): OK  .*$/  PASS  \1/p; s/^\(.*\): FAIL  .*$/  FAIL  \1/p' /tmp/gw_guard_client.out
grep -v ': OK  \|: FAIL  ' /tmp/gw_guard_client.out | head -5

echo "  --- 服务端 ---"
PASS=$(grep -c ': OK  ' /tmp/gw_guard_client.out)
FAIL=$(grep -c ': FAIL  ' /tmp/gw_guard_client.out)

# 客户端一共触发 4 次异常（x / p1 / q1 / q2）：每一次都要在 Logger 里留一行
# 带 fd / uid / msgId 的 error，**且只有这些**——多出来的说明有帧被重复处理。
ERRLINES=$(grep -c '"msg":"gateway handler error"' "$SRV_LOG")
if [ "$ERRLINES" -eq 4 ] && grep -q '"msgId":2001' "$SRV_LOG" && grep -q '"err":"boom fd=' "$SRV_LOG"; then
    echo "  PASS  服务端：4 次异常各记一行结构化 error（fd/uid/msgId/err）"
    PASS=$((PASS + 1))
else
    echo "  FAIL  服务端：handler error 日志行数=$ERRLINES（期望 4）"
    FAIL=$((FAIL + 1))
fi

# 最关键的一条：不能再有逃到 Swoole 的异常。
# "PHP Fatal error" 是异常穿出 handler 的证据；就算客户端那边全都回包了，
# 它也意味着这条路径没被兜住，只是这次没丢帧。
if grep -q 'PHP Fatal error\|Uncaught' "$SRV_ERR" "$SRV_LOG"; then
    echo "  FAIL  服务端：stderr/stdout 里有逃逸的异常"
    grep -h 'PHP Fatal error\|Uncaught' "$SRV_ERR" "$SRV_LOG" | head -3 | sed 's/^/        /'
    FAIL=$((FAIL + 1))
else
    echo "  PASS  服务端：没有逃逸到 Swoole 的异常"
    PASS=$((PASS + 1))
fi

STARTS=$(grep -c '"msg":"worker start"' "$SRV_LOG")
# 只数 worker 自己的 pid：`server shutdown` 那一行是 master 进程打的（以前走强杀路径时
# 它根本不出现；优雅停机之后才打），把它算进来会把"同一个 worker"误判成两个进程
PIDS=$(grep '"msg":"worker start"' "$SRV_LOG" | grep -o '"pid":[0-9]*' | sort -u | wc -l)
if [ "$STARTS" -eq 1 ] && [ "$PIDS" -eq 1 ]; then
    echo "  PASS  服务端：进程存活（worker start 1 次、同一个 worker pid）"
    PASS=$((PASS + 1))
else
    echo "  FAIL  服务端：worker start=$STARTS 次、worker pid=$PIDS 个"
    FAIL=$((FAIL + 1))
fi

if [ "$CLIENT_RC" -ne 0 ]; then
    echo "  FAIL  客户端异常退出 rc=$CLIENT_RC"
    FAIL=$((FAIL + 1))
fi

echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
