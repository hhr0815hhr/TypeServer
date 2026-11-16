#!/bin/bash
# 把 demo/ 从框架仓库里拆出去，变成独立仓库 + 子模块。
#
# 用法（先在你的 GitHub 上建一个空仓库，比如 tsf-demo）：
#   bash scripts/split-demo.sh git@github.com:you/tsf-demo.git
#
# 为什么用 subtree split 而不是 cp：demo 的提交历史要跟着走。
# 直接拷一份过去，`git log` 就断了——以后想查"这行代码为什么这么写"没有上下文。
set -eu

URL=${1:-}
if [ -z "$URL" ]; then
    echo "用法: bash scripts/split-demo.sh <远程仓库URL>" >&2
    echo "  例: bash scripts/split-demo.sh git@github.com:you/tsf-demo.git" >&2
    exit 1
fi

cd "$(dirname "$0")/.."

if [ -d demo/.git ]; then
    echo "demo/ 已经是独立仓库了，不需要再拆" >&2
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "工作区有未提交的改动，先提交再拆——拆到一半失败会很难收拾" >&2
    exit 1
fi

echo "== 1/4 抽出 demo 的独立历史 =="
git subtree split --prefix=demo -b demo-split

echo "== 2/4 推到 $URL =="
git push "$URL" demo-split:main

echo "== 3/4 主仓库换成子模块 =="
git branch -D demo-split
git rm -r --cached demo > /dev/null
rm -rf demo
git submodule add "$URL" demo

echo "== 4/4 提交 =="
git commit -m "chore: demo 迁出为独立仓库的子模块

demo 是用框架写的示例，不是框架本体的一部分。放在同一个仓库里会让
「框架体积」和「示例体积」混在一起，也让 bench/ 与 demo/ 的定位变得模糊
（前者是 tsf verify 依赖的验证集，后者是可以独立演进的产品示例）。

用 git subtree split 保留历史；克隆时记得 git clone --recurse-submodules。"
# 注意：**不要**改 .gitignore 里的 demo 规则。现有规则是精确的
# （/demo/duel/project.yml、demo/*/var/**），而 submodule 走的是 gitlink，
# 本来就不受 ignore 影响。改成 `demo/` 只会冲掉那两条精确规则。

echo
echo "完成。后续克隆："
echo "  git clone --recurse-submodules <主仓库>"
echo "  # 或已克隆的仓库里：git submodule update --init --recursive"
