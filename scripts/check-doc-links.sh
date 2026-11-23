#!/bin/bash
# 检查文档里的相对链接是否都指向真实存在的文件。
#
# 为什么需要：quickstart 的"下一步"表里 6 个链接**全部失效**（章节重编号后没同步）。
# 新人照着走完能跑起来，但想看下一步时每个链接都是 404——比报错更劝退，
# 因为它连"哪里错了"都不提示。
#
# 这类问题靠人眼看不住（改一次章节编号，全库的链接都可能失效），必须机器检查。
#
#   bash scripts/check-doc-links.sh
set -u
cd "$(dirname "$0")/.." || exit 1

echo "== 文档相对链接检查 =="
echo ""

python3 - <<'PY'
import os
import re
import sys

root = os.getcwd()
bad = []
checked = 0

for dirpath, dirnames, files in os.walk("docs"):
    dirnames[:] = [d for d in dirnames if d not in ("archive", "spike")]
    for name in files:
        if not name.endswith(".md"):
            continue
        path = os.path.join(dirpath, name)
        with open(path, encoding="utf-8") as fh:
            text = fh.read()
        # 只查相对链接：[文字](xxx.md) 或 (xxx.md#anchor)
        for m in re.finditer(r"\]\(([^)]+)\)", text):
            target = m.group(1).strip()
            if target.startswith(("http://", "https://", "#", "mailto:")):
                continue
            target = target.split("#", 1)[0]
            if not target.endswith(".md"):
                continue
            checked += 1
            resolved = os.path.normpath(os.path.join(dirpath, target))
            if not os.path.isfile(resolved):
                line = text[: m.start()].count("\n") + 1
                bad.append((path, line, target))

print("检查 %d 个文档链接" % checked)
if not bad:
    print("  OK 全部有效")
    sys.exit(0)
print("")
for path, line, target in bad:
    print("  FAIL %s:%d -> %s" % (path, line, target))
print("")
print("共 %d 个失效链接" % len(bad))
sys.exit(1)
PY

rc=$?
echo ""
if [ "$rc" -eq 0 ]; then
    echo "PASS 文档链接全部有效"
else
    echo "FAIL 有失效链接（见上）"
fi
exit "$rc"
