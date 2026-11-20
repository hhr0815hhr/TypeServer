#!/usr/bin/env bash
# 集群通道回归：线格式单元 + TCP 握手/鉴权/去重 + 跨进程 UDS 往返。
#
# 两点与旧版的差别：
#   1. 以前不 build，直接跑 ./cluster_test —— 新克隆跑到这里才报"文件不存在"，
#      而本机编过的人看到的是绿的（假绿比报错更费时间）；
#   2. 以前只测 UDS。加了 TCP 之后握手是新增的攻击面（谁能连上、拿什么证明身份），
#      所以拒斥路径必须有断言，不能只测成功路径。
set -u
cd "$(dirname "$0")" || exit 1

ROOT=$(cd ../.. && pwd)
TSF="$ROOT/tsf/bin/tsf"
BIN=./cluster_test
N=${1:-2000}
FAILS=0

if ! php85 "$TSF" build . > /tmp/cluster_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/cluster_test_build.log）"
    tail -5 /tmp/cluster_test_build.log
    exit 1
fi

if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

echo "== 线格式单元 =="
OUT=$("$BIN" unit 2>&1)
echo "$OUT"
echo "$OUT" | grep -q "FAIL" && FAILS=$((FAILS + 1))

echo
echo "== TCP / 握手 =="
OUT=$("$BIN" tcp 2>&1)
echo "$OUT"
echo "$OUT" | grep -q "  FAIL  " && FAILS=$((FAILS + 1))

echo
echo "== 跨进程往返（UDS，node2 → node1）=="
pkill -9 -x cluster_test 2>/dev/null
rm -f /tmp/tsf_node1.sock /tmp/tsf_node2.sock
sleep 0.2

"$BIN" server > /tmp/cluster_srv.log 2>&1 &
for _ in $(seq 1 50); do
    [ -S /tmp/tsf_node1.sock ] && break
    sleep 0.05
done

"$BIN" client "$N" 2>&1 || FAILS=$((FAILS + 1))

sleep 0.2
pkill -9 -x cluster_test 2>/dev/null
echo "--- 服务端 ---"
cat /tmp/cluster_srv.log

echo
if [ "$FAILS" -eq 0 ]; then
    echo "结果: 全部通过"
    exit 0
fi
echo "结果: $FAILS 组失败"
exit 1
