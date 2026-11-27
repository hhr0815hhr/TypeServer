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

# 每次都 build（增量，源码没变时约 1 秒）。
#
# 2026-11-27 之前这里是 `if [ ! -x demo_echo ]` 才 build——产物存在就跳过。当天它跑的就是
# 一个旧产物：那个 demo_echo 是用 /tmp 下的临时工具链链的，动态库路径指向已被清掉的目录，
# 服务一起来就死。而 `tsf lint` 的"必须编译"规则只认 run.sh 里有没有 "build" 字样，
# 这种写法能过嗅探。**产物存在不代表它是当前代码、也不代表它还能加载**，所以无条件重编；
# 新克隆的仓库照常由这一步负责首次构建。
#
# 先删再编：增量编译只看"源码有没有变"。产物还在、源码没动时它不会重新链接，
# 所以一个坏掉的产物（动态库路径失效）能一直留着——变异检查时实测过：只 build 不删，
# 坏产物原样保留，套件照样失败。
rm -f "$DEMO/demo_echo"
(cd "$DEMO" && php85 "$ROOT/tsf/bin/tsf" build . > /tmp/frame_test_build.log 2>&1) || {
    echo "FAIL demo-echo 构建失败（日志 /tmp/frame_test_build.log）"
    tail -3 /tmp/frame_test_build.log
    exit 1
}
if [ ! -x "$DEMO/demo_echo" ]; then
    echo "FAIL 构建成功但没找到产物 $DEMO/demo_echo"
    exit 1
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

# 服务起来就死时要说出**服务**的死因，而不是等客户端超时后报"connect timed out"——
# 那会把"动态库加载失败"伪装成"网络问题"（2026-11-27 实际发生过）
if ! kill -0 "$SRV" 2>/dev/null; then
    echo "FAIL demo_echo 启动后退出（日志 /tmp/frame_test_srv.log）"
    tail -3 /tmp/frame_test_srv.log
    exit 1
fi

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
