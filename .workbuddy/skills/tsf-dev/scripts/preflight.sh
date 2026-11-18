#!/bin/bash
# 提交前的自检：环境 + 静态检查 + 常见错误扫描。
#
# 用法：bash preflight.sh [工程目录，默认当前目录]
#
# 退出码：0 全过；1 有问题（细节看输出）

set -u
PROJ="${1:-$(pwd)}"
FAIL=0

# 探测 tsf：PATH > 同级的 tsf/bin > vendor/bin
# 三者都可能出现（开发机 / 主仓库内跑 / 装了 composer 包的工程）
TSF_BIN="${TSF_BIN:-}"
# 探测 tsf，优先级：
#   1. TSF_BIN 环境变量（独立工程 / CI 里手动指定）
#   2. PATH
#   3. 逐级往上找 tsf/bin（主仓库内的工程，如 tests/xxx、demo/xxx）
#   4. vendor/bin（composer require 装的独立工程）
if [ -n "${TSF_BIN:-}" ] && [ -x "${TSF_BIN}" ]; then
    : # 已由环境变量指定
elif command -v tsf > /dev/null 2>&1; then
    TSF_BIN="tsf"
else
    # 逐级往上找（工程可能嵌在 tests/ 或 demo/ 下面）
    for cand in \
        "$PROJ/vendor/bin/tsf" \
        "$PROJ/tsf/bin/tsf" \
        "$PROJ/../tsf/bin/tsf" \
        "$PROJ/../../tsf/bin/tsf" \
        "$PROJ/../../../tsf/bin/tsf"; do
        if [ -x "$cand" ]; then
            TSF_BIN="$cand"
            break
        fi
    done
fi

say() { printf '%-34s' "$1"; }
ok()  { echo "OK"; }
bad() { echo "FAIL  $1"; FAIL=1; }

echo "== 1/4 环境（tsf doctor）=="
if [ -n "$TSF_BIN" ]; then
    if "$TSF_BIN" doctor > /tmp/_tsf_doctor.txt 2>&1; then
        say "tsf doctor"; ok
    else
        say "tsf doctor"; bad "看 /tmp/_tsf_doctor.txt"
    fi
else
    say "tsf 命令"; bad "找不到（PATH / 同级 tsf/bin / vendor/bin 都没有）"
fi

echo
echo "== 2/4 静态检查（tsf lint）=="
if [ -f "$PROJ/project.json" ]; then
    if "$TSF_BIN" lint "$PROJ" > /tmp/_tsf_lint.txt 2>&1; then
        say "tsf lint"; ok
    else
        say "tsf lint"; bad "看 /tmp/_tsf_lint.txt"
    fi
else
    say "project.json"; bad "不在 $PROJ，跳过 lint"
fi

echo
echo "== 3/4 常见错误扫描 =="

# mixed 类型声明
n=$(grep -rn ': mixed\|(mixed ' --include='*.php' "$PROJ/src" 2>/dev/null | wc -l)
say "mixed 类型声明"; [ "$n" -eq 0 ] && ok || bad "找到 $n 处，AOT 下应改用具体类型"

# Timer 回调漏参数
n=$(grep -rn 'Timer::tick(.*function ()\|Timer::after(.*function ()' --include='*.php' "$PROJ" 2>/dev/null | wc -l)
say "Timer 回调空参"; [ "$n" -eq 0 ] && ok || bad "找到 $n 处，形参必须是 int \$timerId"

# 手写 project.yml（会漏 -O2）
if [ -f "$PROJ/project.yml" ]; then
    if grep -q 'tsf build 生成' "$PROJ/project.yml" 2>/dev/null; then
        say "project.yml 来源"; ok
    else
        n=$(grep -c 'cxx-flags' "$PROJ/project.yml" 2>/dev/null || echo 0)
        [ "$n" -gt 0 ] && { say "project.yml 手写?"; ok; } || bad "缺 cxx-flags，可能是手写的"
    fi
else
    say "project.yml"; echo "（还没生成，跑 tsf build）"
fi

echo
echo "== 4/4 编译 =="
if [ -f "$PROJ/project.json" ]; then
    if timeout 600 "$TSF_BIN" build "$PROJ" > /tmp/_tsf_build.txt 2>&1; then
        say "tsf build"; ok
    else
        say "tsf build"; bad "看 /tmp/_tsf_build.txt，然后查 references/error-codes.md"
    fi
fi

echo
if [ "$FAIL" -eq 0 ]; then
    echo "全部通过"
else
    echo "有失败项 —— 编译错误的对照表在 references/error-codes.md"
fi
exit "$FAIL"
