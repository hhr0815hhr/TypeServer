#!/usr/bin/env bash
# 配置表工具链回归：Excel → 代码 + 二进制 → AOT 编译 → 运行期加载。
#
# 顺手验两件容易漏的事：
#   1. 校验失败时**一个字节都不写**（不留半成品给运行期捡到）
#   2. 改数值不需要重新编译（数据在 .bin 里）—— 这条是 T6-02 热更的前提
set -u
cd "$(dirname "$0")" || exit 1
TSF=/data/TypeServer/tsf/bin/tsf

echo "=== 1. 校验失败要中断，且不写产物 ==="
rm -rf gen build
cp config/tables/item.xlsx /tmp/ct_item_backup.xlsx
# 坏表放在 config/fixtures/（不在 tables/ 里），需要时临时拷进去
cp config/fixtures/bad.xlsx config/tables/bad.xlsx
$TSF config build . > /tmp/ct_bad.log 2>&1
RC=$?
grep -E "✗|第 .* 行" /tmp/ct_bad.log | head -6
if [ "$RC" -ne 0 ] && [ ! -d gen ] && [ ! -d build ]; then
  echo "PASS 校验失败即中断，且没有留下 gen/ 或 build/"
else
  echo "FAIL 校验失败但产物被写了（rc=$RC）"
fi
rm -f config/tables/bad.xlsx

echo
echo "=== 2. 正常导出 + AOT 编译 ==="
$TSF config build . 2>&1 | head -6
$TSF build . 2>&1 | grep -E "Build successful|Fatal|error:" | head -3

echo
echo "=== 3. 运行期加载 ==="
./config_test | tail -6

echo
echo "=== 4. 只改数据不重编 ==="
# 直接改 .bin 里的一个字节是不靠谱的（格式有长度前缀），
# 这里用工具重新导出一份，但不重新编译，看程序是否读到新值。
# 允许用 python 改 xlsx：这只在测试里做，真实流程是策划改表后重跑 config build
python3 - <<'PY' 2>/dev/null || true
import openpyxl
p = "/data/TypeServer/bench/config-test/config/tables/item.xlsx"
wb = openpyxl.load_workbook(p)
ws = wb["Item"]
# 第 4 行数据（Excel 行号 4）的 name 改成"木剑·改"
ws.cell(row=4, column=2).value = "木剑·改"
wb.save(p)
print("已改 item.xlsx 第 4 行 name")
PY
$TSF config build . > /dev/null 2>&1
OUT=$(./config_test 2>&1)
if echo "$OUT" | grep -q "FAIL"; then
  # 断言里仍然期望"木剑"，说明数据确实变了 —— 这反而证明读的是文件不是常量
  echo "PASS 改数据不重编，程序读到了新值（旧断言因此变红，符合预期）"
  echo "$OUT" | grep "FAIL" | head -2
else
  echo "FAIL 改了数据但程序还是旧值（数据被编进二进制了？）"
fi
# 还原
cp /tmp/ct_item_backup.xlsx config/tables/item.xlsx
$TSF config build . > /dev/null 2>&1
./config_test | tail -1

echo
echo "=== 5. 末尾追加一列：只重跑导出，不重编程序 ==="
python3 - <<'PY' 2>/dev/null || echo "  （需要 openpyxl，跳过）"
import openpyxl
p = "/data/TypeServer/bench/config-test/config/tables/item.xlsx"
wb = openpyxl.load_workbook(p)
ws = wb["Item"]
# 末尾加一列：统计用的"活动倍率"，只有策划关心，程序这一版还不认识
col = ws.max_column + 1
ws.cell(row=1, column=col).value = "eventRate"
ws.cell(row=2, column=col).value = "float(0..9.9)"
for r in range(3, ws.max_row + 1):
    ws.cell(row=r, column=col).value = 1.0
wb.save(p)
print("  已在 Excel 末尾追加一列 eventRate")
PY
$TSF config build . 2>&1 | grep -E "item:" 
# **注意：这里不跑 tsf build**，还是刚才那个二进制
OUT5=$(./config_test 2>&1)
echo "$OUT5" | tail -1
if echo "$OUT5" | grep -q "^结果: 43 通过 / 0 失败"; then
  echo "PASS 旧程序读新数据（末尾多一列）完全正常，不需要重编"
else
  echo "FAIL 末尾追加列把旧程序弄坏了"
  echo "$OUT5" | grep FAIL | head -3
fi
# 还原
cp /tmp/ct_item_backup.xlsx config/tables/item.xlsx
$TSF config build . > /dev/null 2>&1
echo "--- 还原后 ---"
./config_test | tail -1
