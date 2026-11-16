#!/usr/bin/env bash
# T6-02 热更回归：改数据 → 拷进运行中的进程 → 多久生效。
#
# 掐表分两段，因为这两段的性质完全不同：
#   A. 导出（Excel → .bin）   —— 一次性的工具开销，可以手动跑也可以挂监听
#   B. 落地（.bin 变了 → 内存生效）—— **这才是"热更"**，验收线看这一段
#
# 为了把 B 单独量出来，先在 /tmp 里把新配置导出好，再用一次 cp 让它"落地"。
set -u
cd "$(dirname "$0")" || exit 1
TSF=/data/TypeServer/tsf/bin/tsf
CFG=build/config

pkill -9 -x config_hot 2>/dev/null
$TSF config build . > /dev/null 2>&1

echo "=== 启动（模拟已经在跑的服务器）==="
./config_hot 25 > /tmp/hot.log 2>&1 &
for _ in $(seq 1 100); do
  grep -q "^ready" /tmp/hot.log 2>/dev/null && break
  sleep 0.05
done
head -1 /tmp/hot.log

echo
echo "=== A. 导出耗时（Excel → .bin，工具开销，不算热更）==="
rm -rf /tmp/hot_stage
cp -r . /tmp/hot_stage
python3 - <<'PY'
import openpyxl
p = "/tmp/hot_stage/config/tables/item.xlsx"
wb = openpyxl.load_workbook(p)
wb["Item"].cell(row=4, column=2).value = "铁剑"
wb.save(p)
PY
T_EXP0=$(date +%s%3N)
$TSF config build /tmp/hot_stage > /dev/null 2>&1
T_EXP1=$(date +%s%3N)
echo "  导出用时 $((T_EXP1 - T_EXP0)) ms"

echo
echo "=== B. 落地 → 生效（这才是热更）==="
T0=$(date +%s%3N)
cp /tmp/hot_stage/$CFG/item.bin $CFG/item.bin
cp /tmp/hot_stage/$CFG/monster.bin $CFG/monster.bin
# 清单最后拷：它就是"可以来读了"的信号
cp /tmp/hot_stage/$CFG/manifest.txt $CFG/manifest.txt
for _ in $(seq 1 400); do
  grep -q "^生效" /tmp/hot.log 2>/dev/null && break
  sleep 0.005
done
T1=$(date +%s%3N)
LINE=$(grep -m1 "^生效" /tmp/hot.log)
echo "  $LINE"
DELTA=$((T1 - T0))
echo "  从落地到生效：$DELTA ms（验收 ≤100 ms）"
if [ "$DELTA" -le 100 ] && echo "$LINE" | grep -q "铁剑"; then
  echo "PASS 100 ms 内生效，值也对"
else
  echo "FAIL 生效太慢或值不对"
fi

echo
echo "=== C. 坏数据不能影响线上 ==="
# 造一个被截断的 item.bin，并在清单里给它一个新的 crc
python3 - <<'PY'
import zlib, os
p = "/data/TypeServer/bench/config-hot/build/config/item.bin"
data = open(p, "rb").read()[:80]          # 截断
open(p, "wb").write(data)
crc = zlib.crc32(data) & 0xFFFFFFFF
m = "/data/TypeServer/bench/config-hot/build/config/manifest.txt"
lines = [l for l in open(m).read().split("\n") if l and not l.startswith("#")]
out = ["# tsf config manifest v1"]
for l in lines:
    parts = l.split(" ")
    if parts[0] == "item.bin":
        out.append("item.bin %d %d" % (len(data), crc))
    else:
        out.append(l)
open(m + ".tmp", "w").write("\n".join(out) + "\n")
os.rename(m + ".tmp", m)
PY
sleep 0.2
if grep -q "name=铁剑" /tmp/hot.log; then
  echo "PASS 坏数据被拒，仍然服务旧值（铁剑）"
else
  echo "FAIL 坏数据把好数据顶掉了"
fi

echo
echo "=== 汇总（进程退出时打印）==="
wait 2>/dev/null
pkill -9 -x config_hot 2>/dev/null
grep -E "^(ready|生效|stats)" /tmp/hot.log
