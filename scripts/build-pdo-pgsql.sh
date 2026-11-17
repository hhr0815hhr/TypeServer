#!/bin/bash
# 给 php85（PHP 8.5 ZTS）编译 pdo_pgsql 扩展。
#
# 为什么需要这个脚本：typephp 的 AOT 产物加载的是 php85 的扩展（conf.d 里的 .so），
# 而 php85 编译时只带了 pdo_mysql / pdo_sqlite。要用 PostgreSQL 就必须单独补一个
# pdo_pgsql.so。**重装 php85 后要重跑本脚本**。
#
# 三个坑（都是实测踩出来的，改了任何一步都会失败）：
#
#   1. 必须在**完整 php-src 树**里编。单独 phpize 会失败：
#        pgsql_sql_parser.c: fatal error: ext/pdo/pdo_sql_parser.h: No such file
#      因为那是内部头，不随 `make install` 导出。
#
#   2. 必须显式 --with-php-config 指向 8.5。phpize85 生成的 configure 默认拿的是
#      /usr/local/php-8.3/include/php —— 用 8.3 的头编 8.5 的扩展，会满屏
#      "implicit declaration" / "unknown type name"。
#
#   3. 不能用 pg_config 探测 libpq：deb 里的 pg_config 报的是写死的系统路径
#      （/usr/lib/x86_64-linux-gnu、/usr/include/postgresql），而 libpq 是我们
#      解包到 ~/libpq 的。要用 PGSQL_CFLAGS / PGSQL_LIBS 环境变量绕过。
#
# 前置：
#   - /usr/local/src/php-<VER>.tar.xz（可读即可，解压后我改不了 root 的 600 文件）
#   - libpq 解包到 ~/libpq（本脚本会自动下载）
#   - sudo 权限（仅为最后一步 cp 到系统目录）

set -euo pipefail

PHP_VER="${PHP_VER:-8.5.12}"
PHP_TAR="${PHP_TAR:-/usr/local/src/php-${PHP_VER}.tar.xz}"
PHP_PREFIX="${PHP_PREFIX:-/usr/local/php-8.5-zts}"
EXT_DIR="$PHP_PREFIX/lib/php/extensions/no-debug-zts-20250925"
CONF_DIR="$PHP_PREFIX/etc/conf.d"
WORK="$HOME/pgsql-build"

echo "== 1/5 准备 libpq =="
if [ ! -f "$HOME/libpq/usr/include/postgresql/libpq-fe.h" ]; then
    mkdir -p /tmp/libpq_pkg && cd /tmp/libpq_pkg
    env -u http_proxy -u https_proxy -u HTTP_PROXY -u HTTPS_PROXY \
        apt-get download libpq-dev libpq5
    mkdir -p "$HOME/libpq"
    dpkg -x libpq-dev_*.deb "$HOME/libpq"
    dpkg -x libpq5_*.deb "$HOME/libpq"
    echo "  libpq 已解包到 $HOME/libpq"
else
    echo "  libpq 已在 $HOME/libpq"
fi

echo "== 2/5 解压源码树 =="
if [ ! -f "$WORK/php-src/configure" ]; then
    mkdir -p "$WORK/php-src"
    tar -xJf "$PHP_TAR" -C "$WORK/php-src" --strip-components=1
    echo "  解压完成"
else
    echo "  源码树已存在"
fi

echo "== 3/5 phpize =="
cd "$WORK/php-src/ext/pdo_pgsql"
"$PHP_PREFIX/bin/phpize" > /dev/null
echo "  完成"

echo "== 4/5 configure（关键：显式 php-config + PGSQL_CFLAGS/LIBS）=="
PGSQL_CFLAGS="-I$HOME/libpq/usr/include/postgresql -I$WORK/php-src" \
PGSQL_LIBS="-L$HOME/libpq/usr/lib/x86_64-linux-gnu -lpq" \
    ./configure --with-php-config="$PHP_PREFIX/bin/php-config" --with-pdo-pgsql \
    > "$WORK/configure.log" 2>&1
echo "  完成（日志：$WORK/configure.log）"

echo "== 5/5 编译并安装 =="
make -j"$(nproc)" > "$WORK/make.log" 2>&1
if [ ! -f modules/pdo_pgsql.so ]; then
    echo "  编译失败，看 $WORK/make.log"
    exit 1
fi
echo "  编译成功：$(ls -lh modules/pdo_pgsql.so | awk '{print $5}')"

sudo cp modules/pdo_pgsql.so "$EXT_DIR/"
echo 'extension=pdo_pgsql.so' | sudo tee "$CONF_DIR/pdo_pgsql.ini" > /dev/null

echo
echo "== 验证 =="
"$PHP_PREFIX/bin/php" -r 'echo implode(", ", PDO::getAvailableDrivers()), PHP_EOL;'
echo "看到 pgsql 就成功了。AOT 产物会加载同一套 conf.d，不需要重新编译工程。"
