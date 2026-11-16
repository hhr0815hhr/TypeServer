#!/usr/bin/env bash
# T6-06 回归：改一行代码 → 看到效果。
#
# 把**两段分开量**，因为责任方不同：
#   检测（写入 → watcher 发现）= watcher 的职责
#   编译+重启（发现 → 新版本起来）= tpc 的耗时，watcher 改不动
#
# 测试改的是**同一行里的一个字符**（大小不变、可能同秒），正是 mtime+size 判据会漏的情况。
set -u
cd "$(dirname "$0")" || exit 1
TSF=/data/TypeServer/tsf/bin/tsf
LOG=/tmp/watch_test.log

pkill -9 -x watch_test 2>/dev/null
pkill -9 -f "tsf watch" 2>/dev/null
sleep 0.2

reset_ver() {
python3 - <<'PY'
import re
p = "/data/TypeServer/bench/watch-test/main.php"
s = open(p).read()
s = re.sub(r'boot version=\d+', 'boot version=1', s)
open(p, "w").write(s)
PY
}
reset_ver
rm -f $LOG

$TSF watch . > $LOG 2>&1 &
WPID=$!

wait_for() {   # wait_for <模式> <超时毫秒>
  local pat="$1" budget="$2" spent=0
  while [ "$spent" -lt "$budget" ]; do
    if grep -q "$pat" $LOG 2>/dev/null; then return 0; fi
    sleep 0.01
    spent=$((spent + 10))
  done
  return 1
}

if ! wait_for "boot version=1" 60000; then
  echo "FAIL 首次启动没起来"; tail -20 $LOG; kill -9 $WPID 2>/dev/null; exit 1
fi
echo "首次启动 OK"

run_case() {   # run_case <名字> <新版本号>
  local name="$1" ver="$2"
  sleep 1.1
  local t0 t1 t2
  t0=$(date +%s%3N)
  python3 - "$ver" <<'PY'
import re, sys
v = sys.argv[1]
p = "/data/TypeServer/bench/watch-test/main.php"
s = open(p).read()
s = re.sub(r'boot version=\d+', 'boot version=' + v, s)
open(p, "w").write(s)
PY
  if wait_for "\[watch\] 变更" 8000; then t1=$(date +%s%3N); else t1=$((t0 + 99999)); fi
  if wait_for "boot version=$ver" 20000; then t2=$(date +%s%3N); else t2=$((t1 + 99999)); fi
  DETECT=$((t1 - t0))
  BUILD=$((t2 - t1))
  TOTAL=$((t2 - t0))
  echo "$name：检测 ${DETECT}ms + 编译重启 ${BUILD}ms = **${TOTAL}ms**"
}

echo
run_case "用例1（改一个字符）" 2
R1=$TOTAL
# 同秒内连着改两次：只有内容指纹能发现，mtime+size 会漏
sleep 1.1
T0=$(date +%s%3N)
python3 - <<'PY'
import re
p = "/data/TypeServer/bench/watch-test/main.php"
s = open(p).read()
s = re.sub(r'boot version=\d+', 'boot version=3', s); open(p,"w").write(s)
s = re.sub(r'boot version=\d+', 'boot version=4', s); open(p,"w").write(s)
PY
wait_for "boot version=4" 20000 && T1=$(date +%s%3N) || T1=$((T0 + 99999))
R2=$((T1 - T0))
echo "用例2（同秒内改两次）：**${R2}ms**"

echo
echo "用例3：编译失败不重启，改好后自动恢复"
python3 - <<'PY'
import re
p = "/data/TypeServer/bench/watch-test/main.php"
s = open(p).read()
s = re.sub(r'boot version=\d+', 'boot version=5', s)
s = s.replace("function main(): void\n{", "function main(): void\n{\n    this_is_a_syntax_error(")
open(p, "w").write(s)
PY
FAILED=0; wait_for "编译失败" 15000 && FAILED=1
BAD=0; grep -q "boot version=5" $LOG && BAD=1
python3 - <<'PY'
p = "/data/TypeServer/bench/watch-test/main.php"
s = open(p).read().replace("    this_is_a_syntax_error(", "    ")
open(p, "w").write(s)
PY
REC=0; wait_for "boot version=5" 20000 && REC=1
echo "  失败被报出=$FAILED  坏版本没跑起来=$((1-BAD))  改好后恢复=$REC"

# 看最后一次的编译占比
grep -o "已重启（总计 [0-9]* ms：停 [0-9]* + 编译 [0-9]* + 启动 [0-9]*）" $LOG | tail -1

kill -9 $WPID 2>/dev/null
pkill -9 -x watch_test 2>/dev/null
reset_ver

echo
echo "=== 判定 ==="
P=0; F=0
# watcher 自己的职责：发现改动的延迟
D=$(grep -o "变更" $LOG | wc -l)
[ "$R1" -le 3000 ] && { echo "PASS 端到端 $R1 ms（验收线 3000）"; P=$((P+1)); } || {
  echo "FAIL 端到端 $R1 ms（验收线 3000）"
  # 4.4 s 是老症状：聚合单元 extension-<工程>.cc（157 KB）被重编。
  # 根因是 arginfo 的 Stub hash 随函数体变动，修法见 tpc-patches/README.md。
  echo "  提示：若这个数字回到 4.4 s 左右，很可能是 tpc 被升级、补丁丢了"
  echo "        （补丁在 ~/typephp-src/src/Translator.php，说明见 tpc-patches/README.md）"
  F=$((F+1))
}
[ "$R2" -le 3000 ] && { echo "PASS 同秒两次 $R2 ms"; P=$((P+1)); } || { echo "FAIL 同秒两次 $R2 ms"; F=$((F+1)); }
[ "$FAILED" = 1 ] && [ "$BAD" = 0 ] && [ "$REC" = 1 ] && { echo "PASS 编译失败的处理"; P=$((P+1)); } || { echo "FAIL 编译失败的处理"; F=$((F+1)); }
echo "（检测到 $D 次变更）"
[ "$F" -eq 0 ] && exit 0 || exit 1
