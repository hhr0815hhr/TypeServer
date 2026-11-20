#!/bin/bash
# 示例脚本的公共小工具：挑端口、等端口。
#
# 为什么需要"挑端口"而不是写死：示例/验收脚本固定用 9101/9102，而这两个端口
# 在开发机上经常被别的东西占着（本机上就有 mir_login / mir_game）。写死的后果是
# 客户端连到别人的进程，几十秒后给出一堆和代码无关的 FAIL——比直接报错更费时间。
#
# 做法是业界常见的"占用就 +1 继续试"，但**只在示例/测试里用**：
# 生产服务端不该悄悄换端口，那会让网关和注册表指向一个没人监听的地址。

# pick_port <起始端口> [最多试几个]
# 打印第一个可用的端口；全都不可用返回非 0。
pick_port() {
    local start="${1:-9101}"
    local max="${2:-50}"
    local i p
    for i in $(seq 0 "$max"); do
        p=$((start + i))
        # ss 不可用时退化成"假设可用"，让后续 bind 失败去暴露问题
        if ! command -v ss >/dev/null 2>&1; then
            echo "$p"
            return 0
        fi
        if ! ss -lnt 2>/dev/null | grep -q ":$p "; then
            echo "$p"
            return 0
        fi
    done
    return 1
}

# wait_port <端口> [最多等几秒]
wait_port() {
    local port="$1"
    local tries="${2:-8}"
    local n
    n=$((tries * 10))
    for _ in $(seq 1 "$n"); do
        ss -ltn 2>/dev/null | grep -q ":$port " && return 0
        sleep 0.1
    done
    return 1
}
