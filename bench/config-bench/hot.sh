#!/usr/bin/env bash
# 万行表的热更耗时：这是最坏情况，也是唯一可能顶破 100 ms 的地方。
set -u
cd /data/TypeServer/bench/config-bench || exit 1
TSF=/data/TypeServer/tsf/bin/tsf
CFG=build/config

pkill -9 -x config_bench 2>/dev/null
$TSF config build . > /dev/null 2>&1
$TSF build . 2>&1 | grep -E "Build successful|Fatal" | head -2

./config_bench serve > /tmp/hotbig.log 2>&1 &
for _ in $(seq 1 100); do
  grep -q "^ready" /tmp/hotbig.log 2>/dev/null && break
  sleep 0.05
done
head -1 /tmp/hotbig.log

# 在 /tmp 里把新版本导出好（改第 2 行的名字）
rm -rf /tmp/big_stage
cp -r . /tmp/big_stage
python3 - <<'PY'
import openpyxl
p = "/tmp/big_stage/config/tables/big.xlsx"
wb = openpyxl.load_workbook(p)
wb["Big"].cell(row=4, column=2).value = "改名后的单位1"
wb.save(p)
PY
$TSF config build /tmp/big_stage > /dev/null 2>&1

# 连续测 3 次（每次改一個不同的值，确保 crc 每次都变）
for i in 1 2 3; do
  python3 - "$i" <<'PY'
import sys, openpyxl
i = sys.argv[1]
p = "/tmp/big_stage/config/tables/big.xlsx"
wb = openpyxl.load_workbook(p)
wb["Big"].cell(row=4, column=2).value = "改名后的单位%s" % i
wb.save(p)
PY
  $TSF config build /tmp/big_stage > /dev/null 2>&1
  T0=$(date +%s%3N)
  cp /tmp/big_stage/$CFG/big.bin $CFG/big.bin
  cp /tmp/big_stage/$CFG/manifest.txt $CFG/manifest.txt
  BEFORE=$(grep -c "^生效" /tmp/hotbig.log)
  for _ in $(seq 1 600); do
    NOW=$(grep -c "^生效" /tmp/hotbig.log)
    [ "$NOW" -gt "$BEFORE" ] && break
    sleep 0.005
  done
  T1=$(date +%s%3N)
  echo "第 $i 次：落地→生效 $((T1 - T0)) ms"
done

pkill -9 -x config_bench 2>/dev/null
echo
grep -E "^(ready|生效|stats)" /tmp/hotbig.log | tail -6
