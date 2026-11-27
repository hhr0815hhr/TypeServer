#!/bin/bash
# 接真外部服务的测试共用入口：构建、探测、运行、判定。
#
#   bash scripts/run-svc-test.sh <测试目录名> <mysql|redis|pgsql>
#   例：bash scripts/run-svc-test.sh mail-test mysql
#
# 为什么有一个共享脚本：mail-test / db-mysql-test / db-shards-test / redis-test / db-pg-test
# 结构完全一样（project.json 声明 packages，产物名 = 目录名的 - 换成 _，
# 输出 "  OK  " / "  FAIL  "，失败 exit 1）。五份 run.sh 只会让人改了一份忘另四份。
#
# 缺服务时 exit 77（verify 约定的 SKIP），并说出怎么起——
# "环境没装"不该判红，但也不能 exit 0 假装通过。
set -u
DIR_NAME="${1:-}"
SVC="${2:-mysql}"
if [ -z "$DIR_NAME" ] || [ "$#" -lt 2 ]; then
    echo "用法: run-svc-test.sh <测试目录名> <mysql|redis|pgsql>"
    exit 1
fi
cd "$(dirname "$0")/../tests/$DIR_NAME" || exit 1
ROOT=$(cd ../.. && pwd)
# 用例里要引用包内资源（如 mail 的 migrations 目录）的，从 TSF_ROOT 取；
# 别在用例里写死仓库的绝对路径——CI runner 的检出路径不是 /data/TypeServer。
export TSF_ROOT="$ROOT/tsf"
BIN="./$(echo "$DIR_NAME" | tr '-' '_')"
HOST=127.0.0.1

case "$SVC" in
    mysql) PORT=52315; HINT="bash scripts/dev-mysql.sh" ;;
    redis) PORT=6379;  HINT="bash scripts/dev-redis.sh" ;;
    pgsql) PORT=5432;  HINT="bash scripts/dev-pg.sh" ;;
    *) echo "未知服务: $SVC"; exit 1 ;;
esac
LOG="/tmp/${DIR_NAME}_build.log"

# 端口探测：只判可达性。服务在但密码不对属于真配置问题，交给用例自己去红。
if ! php85 -r 'exit(@stream_socket_client("tcp://127.0.0.1:" . $argv[1], $e, $s, 0.3) === false ? 1 : 0);' "$PORT"; then
    echo "SKIP 需要可用的 $SVC（$HOST:$PORT）"
    echo "     起一个：$HINT"
    exit 77
fi

if ! php85 "$ROOT/tsf/bin/tsf" build . > "$LOG" 2>&1; then
    echo "FAIL 编译（日志 $LOG）"
    tail -5 "$LOG"
    exit 1
fi
if [ ! -x "$BIN" ]; then
    echo "FAIL 没找到产物 $BIN"
    exit 1
fi

OUT=$("$BIN" 2>&1)
RC=$?
echo "$OUT"

# 用例自己也可能判 SKIP（探测和运行之间服务没了）
if [ "$RC" -eq 77 ]; then
    exit 77
fi

# 口径跟着用例走：这些套件打的是 "  OK  " 而不是 "  PASS  "
PASS=$(echo "$OUT" | grep -c '^  OK   ')
FAIL=$(echo "$OUT" | grep -c '^  FAIL ')
echo
echo "passed=$PASS failed=$FAIL"

# 一条都没跑通也算失败：产物起来就挂时 PASS 和 FAIL 都是 0，不能当绿
[ "$FAIL" -eq 0 ] && [ "$PASS" -gt 0 ] && [ "$RC" -eq 0 ] && exit 0 || exit 1
