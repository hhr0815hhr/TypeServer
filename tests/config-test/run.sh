#!/usr/bin/env bash
# 配置表工具链回归：Excel → 代码 + 二进制 → AOT 编译 → 运行期加载。
#
# 顺手验两件容易漏的事：
#   1. 校验失败时**一个字节都不写**（不留半成品给运行期捡到）
#   2. 改数值不需要重新编译（数据在 .bin 里）—— 这条是 T6-02 热更的前提
#
# 2026-11-27 重写判定：此前这个脚本**永远退出码 0**，而且 tsf 的路径写死成
# /data/TypeServer。CI 第一次在 GitHub 上跑时它"通过"了——其实每一步都没执行：
# 找不到 tsf、没有产物、断言行是 FAIL，而退出码是最后一条 tail 的 0。
# 现在：路径跟着仓库走；每个 FAIL 都计数；任何一步缺产物都算失败。
set -u
cd "$(dirname "$0")" || exit 1
TSF="$(cd ../.. && pwd)/tsf/bin/tsf"
FAILS=0

bad() {   # bad <说明>：判定失败，计数
  echo "FAIL $*"
  FAILS=$((FAILS + 1))
}

# 第 4、5 步要用 openpyxl 改 xlsx。缺了就 SKIP（exit 77）：这是开发机工具链的一部分，
# 缺它不代表框架有问题——但也不能 exit 0 假装跑过。
if ! python3 -c "import openpyxl" > /dev/null 2>&1; then
    echo "SKIP 需要 python3 的 openpyxl（pip install openpyxl）"
    exit 77
fi

# 这个套件会原地修改 config/tables/item.xlsx（改完再还原）。中途失败也要还原，所以用 trap。
cp config/tables/item.xlsx /tmp/ct_item_backup.xlsx
trap 'cp /tmp/ct_item_backup.xlsx config/tables/item.xlsx' EXIT

echo "=== 1. 校验失败要中断，且不写产物 ==="
rm -rf gen build
# 坏表放在 config/fixtures/（不在 tables/ 里），需要时临时拷进去
cp config/fixtures/bad.xlsx config/tables/bad.xlsx
$TSF config build . > /tmp/ct_bad.log 2>&1
RC=$?
grep -E "✗|第 .* 行" /tmp/ct_bad.log | head -6
if [ "$RC" -ne 0 ] && [ ! -d gen ] && [ ! -d build ]; then
  echo "PASS 校验失败即中断，且没有留下 gen/ 或 build/"
else
  bad "校验失败但产物被写了（rc=$RC）"
fi
rm -f config/tables/bad.xlsx

echo
echo "=== 2. 正常导出 + AOT 编译 ==="
if ! $TSF config build . > /tmp/ct_build_config.log 2>&1; then
  bad "tsf config build 失败（日志 /tmp/ct_build_config.log）"
  tail -5 /tmp/ct_build_config.log
fi
if ! $TSF build . > /tmp/ct_build.log 2>&1; then
  bad "tsf build 失败（日志 /tmp/ct_build.log）"
  tail -5 /tmp/ct_build.log
fi
grep -E "Build successful|Fatal|error:" /tmp/ct_build.log | head -3
if [ ! -x ./config_test ]; then
  bad "没有找到产物 ./config_test"
fi
if [ "$FAILS" -ne 0 ]; then
  echo
  echo "passed=? 失败 $FAILS（编译阶段就失败了，后面的步骤没有意义）"
  exit 1
fi

echo
echo "=== 3. 运行期加载 ==="
OUT3=$(./config_test 2>&1)
echo "$OUT3" | tail -6
if echo "$OUT3" | grep -q "FAIL"; then
  bad "运行期加载有断言失败"
  echo "$OUT3" | grep "FAIL" | head -5
fi

echo
echo "=== 4. 只改数据不重编 ==="
# 直接改 .bin 里的一个字节是不靠谱的（格式有长度前缀），
# 这里用工具重新导出一份，但不重新编译，看程序是否读到新值。
# 允许用 python 改 xlsx：这只在测试里做，真实流程是策划改表后重跑 config build
python3 - <<'PY'
import openpyxl
p = "config/tables/item.xlsx"   # run.sh 已经 cd 到本目录；不写仓库的绝对路径
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
  # 用例的 FAIL 行是**预期**的。换一个前缀再打出来：原样打印的话，行首是 FAIL，
  # verify（以及任何 grep FAIL 的东西）会把它当成这个套件失败了。
  echo "$OUT" | grep "FAIL" | sed 's/^ */      用例侧（预期）：/' | head -2
else
  bad "改了数据但程序还是旧值（数据被编进二进制了？）"
fi
# 还原
cp /tmp/ct_item_backup.xlsx config/tables/item.xlsx
$TSF config build . > /dev/null 2>&1
./config_test | tail -1

echo
echo "=== 5. 末尾追加一列：只重跑导出，不重编程序 ==="
python3 - <<'PY'
import openpyxl
p = "config/tables/item.xlsx"
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
  bad "末尾追加列把旧程序弄坏了"
  echo "$OUT5" | grep FAIL | head -3
fi
# 还原（trap 里还会再做一次，这里先还原是为了让"还原后"那一行是真的）
cp /tmp/ct_item_backup.xlsx config/tables/item.xlsx
$TSF config build . > /dev/null 2>&1
echo "--- 还原后 ---"
./config_test | tail -1

echo
echo "=== 判定 ==="
if [ "$FAILS" -eq 0 ]; then
  echo "PASS 配置表工具链 5 步全部通过"
  exit 0
fi
echo "FAIL 配置表工具链：$FAILS 项失败"
exit 1
