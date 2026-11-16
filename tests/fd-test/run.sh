#!/usr/bin/env bash
# fd 上限检查的测试。把当前 shell 的 ulimit -n 写出来，供程序做交叉验证
# （AOT 产物里 getenv 被禁用，只能走文件传递）。
set -u
cd "$(dirname "$0")" || exit 1
ulimit -n > /tmp/fd_expect
exec ./fd_test
