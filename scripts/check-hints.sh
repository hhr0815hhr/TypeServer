#!/bin/bash
# 验证"编译失败时给出可操作的修法"。
#
# 为什么单独一个脚本而不是进 tsf verify：
# 它要真的跑一次 AOT 编译（首次约 30 秒），比其它套件慢一个量级，
# 放进 verify 会让每次回归都多等半分钟。手动跑、发版前跑即可。
#
#   bash scripts/check-hints.sh
set -u
TSF="$(cd "$(dirname "$0")/../tsf" && pwd)/bin/tsf"
PHP=php85
DIR=/tmp/tsf-hint-check

rm -rf "$DIR"
mkdir -p "$DIR"
cd "$DIR" || exit 1
cat > project.json <<'JSON'
{
    "name": "hintcheck",
    "packages": []
}
JSON

pass=0
fail=0

check() {
    if [ "$1" = "1" ]; then
        echo "  PASS  $2"
        pass=$((pass + 1))
    else
        echo "  FAIL  $2"
        fail=$((fail + 1))
    fi
}

echo "== 1) 变量类型冲突（同一个名字先后是 string 和数组元素）=="
cat > main.php <<'PHP'
<?php
function bad(): void
{
    $k = "prefix";
    foreach (["a", "b"] as $k) {
        echo $k;
    }
}
function main(): void
{
    bad();
}
PHP
OUT=$("$PHP" "$TSF" build . 2>&1)
echo "$OUT" | grep -q "关键错误" && check 1 "提炼出了关键错误（没有淹没在日志里）" || check 0 "提炼出了关键错误"
echo "$OUT" | grep -q "hint:" && check 1 "给出了修法提示" || check 0 "给出了修法提示"
echo "$OUT" | grep -q "换个变量名" && check 1 "提示是可操作的（不是复述错误）" || check 0 "提示是可操作的"

echo ""
echo "== 2) 顶层执行代码 =="
cat > main.php <<'PHP'
<?php
function main(): void
{
}
main();
PHP
OUT2=$("$PHP" "$TSF" build . 2>&1)
echo "$OUT2" | grep -q "All execution code must be within a function" && check 1 "识别出顶层代码错误" || check 0 "识别出顶层代码错误"
echo "$OUT2" | grep -q "不要在文件顶层直接写语句" && check 1 "给了对应的修法" || check 0 "给了对应的修法"

echo ""
echo "== 3) 正常代码不误报 =="
cat > main.php <<'PHP'
<?php
use TSF\Kernel\Logger;
function main(): void
{
    Logger::info("ok");
}
PHP
OUT3=$("$PHP" "$TSF" build . 2>&1)
echo "$OUT3" | grep -q "hint:" && check 0 "正常代码不应出现 hint" || check 1 "正常代码没有误报 hint"

echo ""
echo "结果: ${pass} 通过 / ${fail} 失败"
rm -rf "$DIR"
[ "$fail" -eq 0 ]
