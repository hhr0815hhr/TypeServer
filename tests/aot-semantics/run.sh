#!/bin/bash
# AOT 语义差分：同一份代码，php85 与 tpc 产物逐用例对比。
#
# 差异分两类：
#   - **已知差异**（known-diff.txt 里登记过）：不动它，只提示
#   - **新差异**：失败退出。新差异要么是 tpc 升级引入的回归，要么是我们新写的
#     代码踩到了 AOT 的语义边界——两种都必须有人看一眼
set -u
cd "$(dirname "$0")" || exit 1

PHP_OUT=/tmp/aot_sem_php.txt
AOT_OUT=/tmp/aot_sem_aot.txt

echo "=== 1. 构建 ==="
if ! timeout 600 /data/TypeServer/tsf/bin/tsf build . > /tmp/aot_sem_build.log 2>&1; then
    echo "构建失败："
    grep -E "error:|Fatal" /tmp/aot_sem_build.log | head -5
    exit 1
fi
echo "OK  aot_semantics"

echo
echo "=== 2. 跑两份 ==="
php85 -r 'require "main.php"; main();' > "$PHP_OUT" 2>&1
if ! ./aot_semantics > "$AOT_OUT" 2>&1; then
    echo "AOT 产物退出码非 0，输出最后几行："
    tail -5 "$AOT_OUT"
fi
echo "php85: $(wc -l < "$PHP_OUT") 行 / AOT: $(wc -l < "$AOT_OUT") 行"

echo
echo "=== 3. 逐用例对比 ==="
python3 - "$PHP_OUT" "$AOT_OUT" <<'PY'
import sys

def load(path):
    out = {}
    for line in open(path, encoding="utf-8", errors="replace"):
        line = line.rstrip("\n")
        if " = " not in line:
            continue
        k, v = line.split(" = ", 1)
        out[k.strip()] = v
    return out

php = load(sys.argv[1])
aot = load(sys.argv[2])

known = set()
try:
    for line in open("known-diff.txt", encoding="utf-8"):
        line = line.strip()
        if line and not line.startswith("#"):
            known.add(line.split()[0])
except FileNotFoundError:
    pass

names = list(php.keys())
new = []
same = 0
old = []
for n in names:
    p = php.get(n)
    a = aot.get(n)
    if p == a:
        same += 1
    elif n in known:
        old.append((n, p, a))
    else:
        new.append((n, p, a))

print("一致 %d / 已知差异 %d / 新差异 %d" % (same, len(old), len(new)))

if old:
    print()
    print("-- 已知差异（已登记，仅供参考）--")
    for n, p, a in old:
        print("  %-28s php=%s" % (n, p))
        print("  %-28s aot=%s" % ("", a))

if new:
    print()
    print("-- 新差异（**必须处理**）--")
    for n, p, a in new:
        print("  %-28s php=%s" % (n, p))
        print("  %-28s aot=%s" % ("", a))
    sys.exit(1)

# 用例数对不上也算问题（漏了 case 说明有 case 名写错）
if set(php.keys()) != set(aot.keys()):
    print()
    print("-- 用例集合不一致 --")
    print("  只出现在 php:", sorted(set(php) - set(aot)))
    print("  只出现在 aot:", sorted(set(aot) - set(php)))
    sys.exit(1)

sys.exit(0)
PY
RC=$?

echo
if [ "$RC" = "0" ]; then
    echo "判定：PASS（没有新差异）"
else
    echo "判定：FAIL（有新差异，见上）"
fi
exit $RC
