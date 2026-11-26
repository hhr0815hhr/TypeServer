#!/bin/bash
# 停机守卫：进程收到 SIGTERM 之后，该做的事有没有做。
#
# 为什么进回归：修之前（2026-11-25 实测）SIGTERM 之后框架什么都不做——
# `Service::onStop` 只有 kill() 会调，`Discovery\LocalNode` 指望在 onStop 里注销、
# 账本指望在这里落最后一次快照，全都不执行；空闲进程还要等满 max_wait_time 被强杀
# （ERRNO 9101 worker exit timeout, forced termination）。不崩、不报错，只是"该保存的没保存"。
#
# 三个场景，都靠进程退出后留下的 TRACE 文件和服务端日志判定：
#   1. 排空：停机钩子里排进 100 条消息 → 全部被处理，一条不丢
#   2. 不排空（预算 0）：同样的 100 条被丢弃，并且**计数可见**
#   3. 排空窗口 1 秒：走的是"窗口到期后强制退出"那条路径，停机流程同样要完整
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
PORT=9415
BIN=./shutdown_guard
TRACE=/tmp/shutdown_guard_trace.log
SRV_LOG=/tmp/shutdown_guard_srv.log

if ! php85 "$ROOT/tsf/bin/tsf" build . > /tmp/shutdown_guard_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/shutdown_guard_build.log）"
    tail -5 /tmp/shutdown_guard_build.log
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi
if ss -ltn 2>/dev/null | grep -q ":$PORT "; then
    echo "FAIL 端口 $PORT 已被占用（可能有别的套件在跑）"
    exit 1
fi

ok()  { PASS=$((PASS + 1)); echo "  PASS  $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  FAIL  $1"; }

WANT_HAVE_DRAIN="server-hook
C
B handled=100
A sent=0 timer=0 spawn=-1
scheduler-hook"
WANT_NO_DRAIN="server-hook
C
B handled=0
A sent=0 timer=0 spawn=-1
scheduler-hook"

# run_scenario <名字> <SG_MAX_WAIT> <SG_DRAIN> <期望的 TRACE> <是否要求快速退出>
run_scenario() {
    NAME="$1"; MW="$2"; DRAIN="$3"; WANT="$4"; FAST="$5"
    pkill -x shutdown_guard 2>/dev/null
    sleep 0.3
    rm -f "$TRACE"
    SG_MAX_WAIT="$MW" SG_DRAIN="$DRAIN" "$BIN" > "$SRV_LOG" 2>&1 &
    SRV=$!
    for _ in $(seq 1 50); do
        ss -ltn 2>/dev/null | grep -q ":$PORT " && break
        sleep 0.1
    done
    sleep 0.4
    T0=$(date +%s%N)
    kill -TERM "$SRV"
    for _ in $(seq 1 500); do
        kill -0 "$SRV" 2>/dev/null || break
        sleep 0.02
    done
    T1=$(date +%s%N)
    wait "$SRV" 2>/dev/null
    RC=$?
    MS=$(( (T1 - T0) / 1000000 ))

    echo
    echo "== 场景：$NAME（max_wait=${MW}s drain=${DRAIN}ms）=="
    echo "  退出耗时 ${MS}ms，rc=$RC"

    GOT=$(cat "$TRACE" 2>/dev/null)
    if [ "$GOT" = "$WANT" ]; then
        ok "停机流程完整且顺序正确（钩子 → C → B → A → 收尾钩子）"
    else
        bad "停机流程不对，TRACE 实际内容："
        echo "$GOT" | sed 's/^/        | /'
        echo "        （期望）"
        echo "$WANT" | sed 's/^/        | /'
    fi

    if grep -q 'PHP Fatal error\|Uncaught' "$SRV_LOG"; then
        bad "服务端有逃逸的异常（onStop 抛异常没有被接住）"
        grep 'PHP Fatal error\|Uncaught' "$SRV_LOG" | head -3 | sed 's/^/        /'
    else
        ok "C 的 onStop 抛异常被接住，没有逃逸"
    fi

    if grep -q '"msg":"service onStop threw during shutdown"' "$SRV_LOG"; then
        ok "onStop 抛异常记了 error 日志"
    else
        bad "onStop 抛异常没有留日志"
    fi

    if [ "$RC" -eq 0 ]; then
        ok "master 正常退出（rc=0）"
    else
        bad "master 退出码 rc=$RC"
    fi

    if [ "$FAST" = "yes" ]; then
        if [ "$MS" -lt 1000 ]; then
            ok "空闲进程快速退出（${MS}ms < 1000ms）"
        else
            bad "退出太慢：${MS}ms（修之前约 2500ms）"
        fi
    fi
}

pkill -x shutdown_guard 2>/dev/null
PASS=0
FAIL=0

run_scenario "排空" 0 500 "$WANT_HAVE_DRAIN" yes

run_scenario "不排空" 0 off "$WANT_NO_DRAIN" yes
# 不排空时被丢掉的消息数必须可见：100 条，一条不少一条不多
if grep -q '"msg":"scheduler shutdown".*"dropped":100' "$SRV_LOG"; then
    ok "丢弃的消息数被如实计数（dropped=100）"
else
    bad "丢弃的消息数不对或没记"
    grep '"msg":"scheduler shutdown"' "$SRV_LOG" | head -2 | sed 's/^/        /'
fi

run_scenario "排空窗口 1 秒" 1 500 "$WANT_HAVE_DRAIN" no
# 窗口路径：Swoole 先让 reactor 跑满 1 秒（带 9101 警告），停机流程在窗口到期后执行。
# stopped 只数 onStop 正常返回的（2 个）；C 抛了异常，计入 failed（1 个）。
if grep -q '"msg":"scheduler shutdown".*"stopped":2,"failed":1' "$SRV_LOG"; then
    ok "窗口到期后的强制退出路径上，三个服务的 onStop 照常执行"
else
    bad "窗口路径上停机流程不完整"
fi

pkill -x shutdown_guard 2>/dev/null
echo
echo "passed=$PASS failed=$FAIL"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
