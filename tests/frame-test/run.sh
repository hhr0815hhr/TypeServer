#!/bin/bash
# 拆包/粘包健壮性：分段发送、两帧合并、非法帧断开、异常后恢复。
#
# 为什么进回归：这四条覆盖的是"协议层出错时服务还活着吗"。
# 拆包写错的表现是"偶发丢消息"，非法帧处理错会"一条坏连接拖垮整个 worker"——
# 都不崩，但都致命。
#
# 依赖 demo-echo 的**默认配置**（server.auth.require=true），client.php 会先登录。
set -u
cd "$(dirname "$0")" || exit 1
ROOT=$(cd ../.. && pwd)
DEMO="$ROOT/demo/demo-echo"
# 端口固定在 config/tsf.json（9001）——demo-echo 不读 TSF_PORT，别指望环境变量覆盖
PORT=9001

# 产物不存在就自己构建（增量，命中缓存时 <1 秒）。不这样做的话，
# 新克隆的仓库跑 verify 会在这里失败，而原因和"框架坏了"看起来一样。
if [ ! -x "$DEMO/demo_echo" ]; then
    (cd "$DEMO" && php85 "$ROOT/tsf/bin/tsf" build . > /tmp/frame_test_build.log 2>&1) || {
        echo "FAIL demo-echo 构建失败（日志 /tmp/frame_test_build.log）"
        tail -3 /tmp/frame_test_build.log
        exit 1
    }
fi

pkill -x demo_echo 2>/dev/null
sleep 0.3

# 端口被别人占着就明确失败，不要静默连到别人的服务上跑出一堆假失败
if ss -ltn 2>/dev/null | grep -q ":$PORT "; then
    echo "FAIL 端口 $PORT 已被占用（可能有别的套件在跑）"
    exit 1
fi

cd "$DEMO" || exit 1
./demo_echo > /tmp/frame_test_srv.log 2>&1 &
SRV=$!
sleep 2

OUT=$(php85 "$ROOT/tests/frame-test/client.php" "$PORT" 2>&1)
RC=$?

pkill -x demo_echo 2>/dev/null
wait "$SRV" 2>/dev/null

PASS=$(echo "$OUT" | grep -c 'OK$')
FAIL=$(echo "$OUT" | grep -c 'FAIL$')

echo "$OUT" | sed -n 's/^\(.*\): OK$/  PASS  \1/p'
echo "$OUT" | sed -n 's/^\(.*\): FAIL$/  FAIL  \1/p'

echo
echo "passed=$PASS failed=$FAIL"
if [ "$RC" -ne 0 ]; then
    echo "客户端异常退出 rc=$RC"
    echo "$OUT" | tail -5
    exit 1
fi
[ "$FAIL" -eq 0 ] && [ "$PASS" -gt 0 ] && exit 0 || exit 1
