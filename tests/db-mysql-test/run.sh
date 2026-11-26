#!/bin/bash
# 接真 MySQL 的套件：实际的构建 / 探测 / 判定在 scripts/run-svc-test.sh。
# 缺库时 exit 77（SKIP）；起库：bash scripts/dev-mysql.sh
set -u
cd "$(dirname "$0")/.." || exit 1
bash ../scripts/run-svc-test.sh db-mysql-test mysql
