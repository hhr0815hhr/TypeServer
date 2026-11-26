#!/bin/bash
# 接真服务的套件：实际的构建 / 探测 / 判定在 scripts/run-svc-test.sh。
# 缺服务时 exit 77（SKIP）
set -u
cd "$(dirname "$0")/.." || exit 1
bash ../scripts/run-svc-test.sh redis-test redis
