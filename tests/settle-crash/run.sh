#!/bin/bash
# 结算的崩溃验证：kill -9 之后账不多不少。
#
# 这里只做"随机制裁"是没意义的——必须断言三件事：
#   余额 == 净额（入账 - 出账）  （不多不少）
#   房间号连续无洞           （只截尾，没丢中间）
#   没有重复 id              （重放不重复发钱）
set -u
cd "$(dirname "$0")" || exit 1
BIN=./settle_crash
DIR=/tmp/settle_crash
PASS=0
FAIL=0

check() {   # check <说明> <verify 输出行>
  local label="$1" line="$2"
  local events balance net gaps dup torn
  events=$(echo "$line" | sed -n 's/.*events=\([0-9]*\).*/\1/p')
  balance=$(echo "$line" | sed -n 's/.*balance=\([0-9]*\).*/\1/p')
  net=$(echo "$line" | sed -n 's/.*net=\([0-9]*\).*/\1/p')
  gaps=$(echo "$line" | sed -n 's/.*gaps=\([0-9]*\).*/\1/p')
  dup=$(echo "$line" | sed -n 's/.*dup=\([0-9]*\).*/\1/p')

  local ok=1
  [ "$balance" = "$net" ] || ok=0
  [ "$gaps" = "0" ] || ok=0
  [ "$dup" = "0" ] || ok=0

  if [ "$ok" = "1" ]; then
    PASS=$((PASS + 1))
    printf 'PASS %-22s %s\n' "$label" "$line"
  else
    FAIL=$((FAIL + 1))
    printf 'FAIL %-22s %s\n' "$label" "$line"
  fi
}

echo "=== 1. 完整跑完 50 局（不杀）==="
rm -rf $DIR
$BIN settle $DIR 50 > /dev/null 2>&1
check "干净跑完" "$($BIN verify $DIR)"
L=$($BIN verify $DIR)
echo "    $L"
EV=$(echo "$L" | sed -n 's/.*events=\([0-9]*\).*/\1/p')
if [ "$EV" = "50" ]; then
  echo "PASS 50 局全部记账"
  PASS=$((PASS + 1))
else
  echo "FAIL 期望 50 条，实际 $EV"
  FAIL=$((FAIL + 1))
fi

echo
echo "=== 2. 同一批房间再结算一遍（模拟重启后重放）==="
OUT=$($BIN repeat $DIR 50 2>&1)
NEW=$(echo "$OUT" | sed -n 's/newly_paid=\([0-9]*\).*/\1/p')
if [ "$NEW" = "0" ]; then
  echo "PASS 重放不重复发钱（newly_paid=0）"
  PASS=$((PASS + 1))
else
  echo "FAIL 重放又发了 $NEW 笔"
  FAIL=$((FAIL + 1))
fi
check "重放后账目不变" "$($BIN verify $DIR)"

echo
echo "=== 3. 随机时刻 kill -9（5 轮，每轮都从 1 重新结算）==="
for i in 1 2 3 4 5; do
  rm -rf $DIR
  $BIN settle $DIR 100000 > /tmp/sc.log 2>&1 &
  PID=$!
  # 100~900ms 之间随机处决：这个区间里进程正在写日志
  MS=$(( (RANDOM % 8 + 1) * 100 ))
  sleep "0.$(printf '%03d' "$MS")"
  kill -9 $PID 2>/dev/null
  wait $PID 2>/dev/null
  LAST=$(sed -n 's/settled \([0-9]*\)/\1/p' /tmp/sc.log | tail -1)
  check "第 $i 轮被杀（最后一行 $LAST）" "$($BIN verify $DIR)"
done

echo
echo "=== 4. 被杀之后继续跑，仍然不重复发钱 ==="
$BIN repeat $DIR 100000 > /dev/null 2>&1
check "续跑后账目一致" "$($BIN verify $DIR)"

echo
echo "=== 5. 借贷混合时被杀（每局发 100 再花 30，净额应是 70 × 局数）==="
for i in 1 2 3; do
  rm -rf $DIR
  $BIN mix $DIR 100000 > /tmp/scm.log 2>&1 &
  PID=$!
  MS=$(( (RANDOM % 8 + 1) * 100 ))
  sleep "0.$(printf '%03d' "$MS")"
  kill -9 $PID 2>/dev/null
  wait $PID 2>/dev/null
  LAST=$(sed -n 's/settled \([0-9]*\)/\1/p' /tmp/scm.log | tail -1)
  check "混合第 $i 轮被杀（最后 $LAST 局）" "$($BIN verify $DIR)"
done

echo
echo "=== 6. 混合续跑：不重复发奖，也不重复扣款 ==="
$BIN mix $DIR 100000 > /dev/null 2>&1
check "混合续跑后账目一致" "$($BIN verify $DIR)"

echo
echo "=== 判定 ==="
echo "PASS $PASS 项 / FAIL $FAIL 项"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
