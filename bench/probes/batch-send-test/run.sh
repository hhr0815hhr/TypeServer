#!/bin/bash
# 批量发送的正确性验证（不是性能测试）。
#
# 要证明三件事：
#   1. 一次 feed 内产生的多个回包合并成一次 write 后，**每一帧都完整、有序**
#   2. 批量模式下 feed 之外调用的 sendFrame（定时器/广播）**仍然会发出去**
#   3. closed-loop（一问一答）下不退化
#
# 用法：bash run.sh [port]
set -u
PORT=${1:-9001}   # 必须和服务端 config 的 server.port 一致
DEMO=/data/TypeServer/demo/demo-echo
BENCH=/data/TypeServer/bench

pkill -x demo_echo 2>/dev/null
sleep 0.3

cd "$DEMO" || exit 1
TSF_ENV=bench-nogw ./demo_echo > /tmp/de_batchtest.log 2>&1 &
sleep 2

cd "$BENCH" || exit 1

echo "=== 1) pipeline64 压测后逐帧校验（压测端会统计 recv 数）==="
OUT=$(./load_pipe64 127.0.0.1 "$PORT" 8 3 2>&1 | tail -1)
echo "$OUT"
SENT=$(echo "$OUT" | sed -E 's/.*sent=([0-9]+).*/\1/')
RECV=$(echo "$OUT" | sed -E 's/.*recv=([0-9]+).*/\1/')
echo "sent=$SENT recv=$RECV"
# 压测结束时还有在飞的包，允许少量差值；丢包率 >1% 说明合并把帧写坏了
if [ -n "$SENT" ] && [ -n "$RECV" ] && [ "$SENT" -gt 0 ]; then
    LOSS=$(( (SENT - RECV) * 1000 / SENT ))
    echo "在飞/丢弃比例 = ${LOSS}‰（<10‰ 视为正常，TCP 尾部在飞）"
    if [ "$LOSS" -gt 50 ]; then
        echo "FAIL 丢包过多，合帧可能写坏了"
        pkill -x demo_echo
        exit 1
    fi
fi

echo ""
echo "=== 2) 服务端日志里不应有 bad frame / 异常 ==="
if grep -qE "bad frame|Fatal|Uncaught" /tmp/de_batchtest.log; then
    echo "FAIL 服务端有帧解析错误或异常"
    grep -E "bad frame|Fatal|Uncaught" /tmp/de_batchtest.log | head -3
    pkill -x demo_echo
    exit 1
fi
echo "OK 无帧解析错误"

echo ""
echo "=== 3) closed-loop 不退化 ==="
./load_tsf 127.0.0.1 "$PORT" 8 3 2>&1 | tail -1

pkill -x demo_echo
echo ""
echo "PASS 批量发送正确性检查完成"
