#!/usr/bin/env bash
# fd 上限检查的测试。把当前 shell 的 ulimit -n 写出来，供程序做交叉验证
# （AOT 产物里 getenv 被禁用，只能走文件传递）。
set -u
cd "$(dirname "$0")" || exit 1
ulimit -n > /tmp/fd_expect

# 必须每次都编译：这个套件曾经不编译，直接跑目录里的旧产物（2026-11-16 编的）
if ! php85 ../../tsf/bin/tsf build . > /tmp/fd_test_build.log 2>&1; then
    echo "FAIL 编译（日志 /tmp/fd_test_build.log）"
    tail -5 /tmp/fd_test_build.log
    exit 1
fi
if [ ! -x ./fd_test ]; then
    echo "FAIL 没找到产物 ./fd_test"
    exit 1
fi
exec ./fd_test
