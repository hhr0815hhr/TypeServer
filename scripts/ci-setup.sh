#!/bin/bash
# 在**干净的 Ubuntu** 上装出一套能跑 `tsf verify` 的环境。
#
#   bash scripts/ci-setup.sh            # 安装（幂等，已装的步骤跳过）
#   bash scripts/ci-setup.sh --check    # 只检查，不装；缺什么列什么，退出码 1
#
# 给 GitHub 托管 runner 用，本地 WSL 也能跑（会一路 skip）。
#
# ## 为什么是一套脚本而不是一个 Dockerfile
#
# `verify` 里有六个套件要连真 MySQL / PostgreSQL / Redis，它们由
# `scripts/dev-*.sh` 用 docker 起。如果 verify 本身跑在容器里，容器内的
# 127.0.0.1 是它自己，这些套件只能 SKIP——覆盖率假性下降。
# 所以环境直接装在 runner 上，数据库用 runner 自带的 docker 起。
#
# ## 两个写死的路径，别改
#
#   /usr/local/php-8.5-zts                  tsf build 生成的 rpath 写死了它
#   $HOME/.tsf/toolchain/typephp/vendor/...  phpx 的 rpath 也写死
#
# 换成 /opt/php85 之类的，产物会报 "error while loading shared libraries"。
# （`docker/Dockerfile` 里也有同一条注释，两处要保持一致。）
#
# ## 环境变量
#
#   PHP_VERSION        默认 8.5.12（必须与 phpx 的 ABI 对齐：major.minor + ZTS）
#   SWOOLE_VERSION     默认 6.2.5（--enable-swoole-thread 要求 ZTS）
#   REDIS_VERSION      默认 6.3.0（phpredis，pkg/redis 直接 `new \Redis()`；首个明确配 8.5 的稳定版）
#   TOOLCHAIN_TAG      默认 toolchain-0.9.6（typephp 编译器 + libphpx.so）
#                      —— 独立 tag 命名空间，不是框架版本号
#   PHP_PREFIX         默认 /usr/local/php-8.5-zts

set -euo pipefail

PHP_VERSION="${PHP_VERSION:-8.5.12}"
SWOOLE_VERSION="${SWOOLE_VERSION:-6.2.5}"
REDIS_VERSION="${REDIS_VERSION:-6.3.0}"
TOOLCHAIN_TAG="${TOOLCHAIN_TAG:-toolchain-0.9.6}"
PREFIX="${PHP_PREFIX:-/usr/local/php-8.5-zts}"

# 并行度：默认按核数，但允许覆盖——CI runner 内存小、容器里 nproc 可能虚高，
# -j16 编译 PHP 会 OOM（现象是 "c++: fatal error: Killed signal terminated"）
JOBS="${JOBS:-$(nproc)}"

CHECK=0
if [ "${1:-}" = "--check" ]; then
    CHECK=1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    SUDO="sudo"
fi

missing=()
note() { printf '  %-10s %s\n' "$1" "$2"; }
ok()   { printf '[ OK ]  %-10s %s\n' "$1" "$2"; }
bad()  { printf '[MISS]  %-10s %s\n' "$1" "$2"; missing+=("$1"); }

# ---------------------------------------------------------------- 探测

php_ok() {
    [ -x "$PREFIX/bin/php" ] || return 1
    "$PREFIX/bin/php" -r 'exit(PHP_ZTS ? 0 : 1);' >/dev/null 2>&1 || return 1
    [ -f "$PREFIX/lib/libphp.so" ] || return 1
    # zip：config-check 套件用它手搓 xlsx；composer 装包也离不开。
    # 2026-11-27 CI 第一次跑时 configure 没带 zip，libzip-dev 装了却没编进去；
    # 补的时候写成了 --enable-zip（PHP 7.3 及以前的名字），8.x 里是 --with-zip——
    # configure 只给一句 "unrecognized options" 警告，不报错，第二次 CI 又白跑了一轮。
    "$PREFIX/bin/php" -r 'exit(class_exists("ZipArchive") ? 0 : 1);' >/dev/null 2>&1 || return 1
    return 0
}

redis_ok() {
    # 不能只看 .so 在不在：NTS 的 php-config 编出来的 .so 存在、但加载不上
    "$PREFIX/bin/php" -r 'exit(class_exists("Redis") ? 0 : 1);' >/dev/null 2>&1
}

swoole_ok() {
    "$PREFIX/bin/php" -m 2>/dev/null | grep -qix swoole || return 1
    "$PREFIX/bin/php" -r 'exit(phpversion("swoole") === getenv("WANT_SWOOLE") ? 0 : 1);' >/dev/null 2>&1 || return 1
    return 0
}

# 工具链的期望版本：tag `toolchain-0.9.6` → 0.9.6
WANT_TC_VER="${TOOLCHAIN_TAG#toolchain-}"

# 不能只看"文件在"：早期装过旧包时文件同样存在，但版本是别的。
# 这条让 `--check` 真的能发现"装过但装错了"。
tpc_ok() {
    [ -f "$HOME/.tsf/toolchain/typephp/bin/tpc.php" ] || return 1
    [ -x "$PREFIX/bin/php" ] || return 1
    local v
    v="$("$PREFIX/bin/php" "$HOME/.tsf/toolchain/typephp/bin/tpc.php" --version 2>/dev/null \
        | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+' | head -1 | tr -d 'v' || true)"
    [ "$v" = "$WANT_TC_VER" ]
}
phpx_ok() { [ -f "$HOME/.tsf/toolchain/typephp/vendor/swoole/phpx/lib/libphpx.so" ]; }
interp_ok() {
    # 扩展目录名带 API 号（no-debug-zts-20250925），不能写死；用 glob 展开
    local f
    # 源码树里的 modules/interp.so 也算：所有套件都用 PHP_INI_SCAN_DIR 直接指它，
    # 不依赖 make install 到扩展目录
    for f in "$PREFIX"/lib/php/extensions/*/interp.so "$ROOT"/tsf/ext/interp/modules/interp.so; do
        [ -f "$f" ] && return 0
    done
    return 1
}

echo "== 环境检查 =="
if php_ok; then
    ok "php" "$("$PREFIX/bin/php" -r 'echo PHP_VERSION . " ZTS";') ($PREFIX)"
else
    bad "php" "$PHP_VERSION ZTS + libphp.so（--enable-embed=shared）"
fi

export WANT_SWOOLE="$SWOOLE_VERSION"
if [ -x "$PREFIX/bin/php" ] && swoole_ok; then
    ok "swoole" "$SWOOLE_VERSION (thread)"
else
    bad "swoole" "$SWOOLE_VERSION --enable-swoole-thread"
fi

if tpc_ok && phpx_ok; then
    ok "toolchain" "$TOOLCHAIN_TAG"
else
    bad "toolchain" "$TOOLCHAIN_TAG（tpc + libphpx.so）"
fi

if redis_ok; then
    ok "phpredis" "$REDIS_VERSION"
else
    bad "phpredis" "$REDIS_VERSION（pkg/redis 直接 new \\Redis()，缺了 redis-test / lock-test 必红）"
fi

if python3 -c "import openpyxl" > /dev/null 2>&1; then
    ok "openpyxl" "config-test 第 4、5 步要用"
else
    bad "openpyxl" "缺了 config-test 会 SKIP（python3 -m pip install --user openpyxl）"
fi

if [ -x "$PREFIX/bin/php" ] && interp_ok; then
    ok "interp" "ext/interp 已编译（tsf/ext/interp，随包分发）"
else
    bad "interp" "tsf/ext/interp（脚本热更的运行时，源码随 composer 包）"
fi

if [ -x "$PREFIX/bin/php" ] && "$PREFIX/bin/php" -r 'exit(in_array("pgsql", PDO::getAvailableDrivers(), true) ? 0 : 1);' >/dev/null 2>&1; then
    ok "pdo_pgsql" "已编入"
else
    bad "pdo_pgsql" "db-pg 套件需要"
fi

if [ "$CHECK" = "1" ]; then
    echo
    if [ "${#missing[@]}" -eq 0 ]; then
        echo "全部就绪"
        exit 0
    fi
    echo "缺 ${#missing[@]} 项：${missing[*]}"
    exit 1
fi

if [ "${#missing[@]}" -eq 0 ]; then
    echo
    echo "全部就绪，无需安装"
    exit 0
fi

# ---------------------------------------------------------------- 安装

echo
echo "== 系统依赖 =="
# libpq-dev：pdo_pgsql 直接编进 PHP 核心，避免像本地那样单独补 .so
#           （单独补要在完整源码树里 phpize，还得手动指 libpq，见 build-pdo-pgsql.sh）
$SUDO apt-get -o Acquire::Retries=5 update -qq
$SUDO apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
    build-essential autoconf bison re2c pkg-config cmake git curl ca-certificates xz-utils \
    libxml2-dev libssl-dev libsqlite3-dev libcurl4-openssl-dev \
    libonig-dev libzip-dev libpng-dev libgmp-dev libmpfr-dev libpq-dev
echo "  完成"

# ---------------------------------------------------------------- PHP

if ! php_ok; then
    echo
    echo "== PHP $PHP_VERSION ZTS =="
    # 源码树放在 $HOME 下，**不能用 /src + sudo**：CI 里脚本以 runner 用户跑，
    # sudo 建的 /src 归 root，后面的 ./configure 和 make 往里面写会 permission denied。
    # （在容器里以 root 验证时发现不了这个——这是只有托管 runner 才会踩到的坑。）
    SRC="$HOME/php-src/php-$PHP_VERSION"
    if [ ! -f "$SRC/configure" ]; then
        mkdir -p "$HOME/php-src"
        curl -fsSL "https://www.php.net/distributions/php-$PHP_VERSION.tar.gz" -o /tmp/php.tar.gz
        tar -xzf /tmp/php.tar.gz -C "$HOME/php-src"
    fi
    # 参数与 docker/Dockerfile 保持一致，只多一个 --with-pdo-pgsql：
    #   --enable-zts          phpx 的前置条件（多线程各自持有请求上下文）
    #   --enable-embed=shared 产出 libphp.so，AOT 产物要链它
    #   --enable-pcntl        Swoole 多进程 Server 依赖 fork/signal
    #   --enable-posix        框架用了 posix_getpid()
    cd "$SRC"
    ./configure \
        --prefix="$PREFIX" \
        --enable-zts \
        --enable-embed=shared \
        --enable-pcntl \
        --enable-posix \
        --disable-cgi --disable-phpdbg \
        --with-openssl --with-zlib --with-curl \
        --enable-sockets --enable-mbstring --enable-fileinfo \
        --with-sqlite3 --with-pdo-sqlite \
        --with-mysqli --with-pdo-mysql --with-pdo-pgsql \
        --with-zip \
        > /tmp/php-configure.log 2>&1
    make -j"$JOBS" > /tmp/php-make.log 2>&1
    $SUDO make install > /tmp/php-install.log 2>&1
    $SUDO ln -sf "$PREFIX/bin/php" /usr/local/bin/php85
    echo "  装到 $PREFIX"
else
    echo
    echo "== PHP 已就绪，跳过 =="
fi

# ---------------------------------------------------------------- swoole

if ! swoole_ok; then
    echo
    echo "== swoole $SWOOLE_VERSION =="
    curl -fsSL "https://github.com/swoole/swoole-src/archive/refs/tags/v$SWOOLE_VERSION.tar.gz" -o /tmp/swoole.tar.gz
    rm -rf "/tmp/swoole-src-$SWOOLE_VERSION"
    tar -xzf /tmp/swoole.tar.gz -C /tmp
    cd "/tmp/swoole-src-$SWOOLE_VERSION"
    "$PREFIX/bin/phpize" > /dev/null
    ./configure --with-php-config="$PREFIX/bin/php-config" --enable-swoole-thread --enable-openssl \
        > /tmp/swoole-configure.log 2>&1
    make -j"$JOBS" > /tmp/swoole-make.log 2>&1
    $SUDO make install > /tmp/swoole-install.log 2>&1
    # **写到 $PREFIX/lib/php.ini，不是 etc/conf.d**：
    # PHP 默认的 config-file-path 是 $PREFIX/lib，scan-dir 只有 configure 里
    # 显式给了才生效。这一版 configure 没给 scan-dir，写 conf.d 的 ini 会被
    # 完全忽略——现象是 swoole.so 装好了、`php -m` 里没有它。
    # （第一版就是这么踩的，自检直接报 MISS。）
    $SUDO mkdir -p "$PREFIX/lib"
    printf 'extension=swoole.so\n' | $SUDO tee -a "$PREFIX/lib/php.ini" > /dev/null
    echo "  完成"
else
    echo
    echo "== swoole 已就绪，跳过 =="
fi

# ---------------------------------------------------------------- phpredis

if ! redis_ok; then
    echo
    echo "== phpredis $REDIS_VERSION =="
    # 不用 pecl 命令：这里的 PHP 是 ./configure && make install 编的，没带 pear，直接拉源码包 phpize 编。
    # pecl.php.net 偶发抽风，退到 GitHub release。
    curl -fsSL "https://pecl.php.net/get/redis-$REDIS_VERSION.tgz" -o /tmp/redis.tgz \
        || curl -fsSL "https://github.com/phpredis/phpredis/archive/refs/tags/$REDIS_VERSION.tar.gz" -o /tmp/redis.tgz
    rm -rf "/tmp/redis-src-$REDIS_VERSION"
    mkdir -p "/tmp/redis-src-$REDIS_VERSION"
    tar -xzf /tmp/redis.tgz -C "/tmp/redis-src-$REDIS_VERSION" --strip-components=1
    cd "/tmp/redis-src-$REDIS_VERSION"
    "$PREFIX/bin/phpize" > /dev/null
    ./configure --with-php-config="$PREFIX/bin/php-config" > /tmp/redis-configure.log 2>&1
    make -j"$JOBS" > /tmp/redis-make.log 2>&1
    $SUDO make install > /tmp/redis-install.log 2>&1
    # 同 swoole：ini 必须写 $PREFIX/lib/php.ini（这一版 configure 没给 scan-dir）
    printf 'extension=redis.so\n' | $SUDO tee -a "$PREFIX/lib/php.ini" > /dev/null
    echo "  完成"
else
    echo
    echo "== phpredis 已就绪，跳过 =="
fi

# ---------------------------------------------------------------- toolchain

if ! tpc_ok || ! phpx_ok; then
    echo
    echo "== typephp 工具链 $TOOLCHAIN_TAG =="
    TSF_TOOLCHAIN_TAG="$TOOLCHAIN_TAG" bash "$ROOT/scripts/install-toolchain.sh"
else
    echo
    echo "== toolchain 已就绪，跳过 =="
fi

# ---------------------------------------------------------------- interp

if ! interp_ok; then
    echo
    echo "== ext/interp =="
    cd "$ROOT/tsf/ext/interp"
    "$PREFIX/bin/phpize" > /dev/null
    ./configure --with-php-config="$PREFIX/bin/php-config" > /tmp/interp-configure.log 2>&1
    make -j"$JOBS" > /tmp/interp-make.log 2>&1
    $SUDO make install > /tmp/interp-install.log 2>&1
    echo "  完成"
else
    echo
    echo "== interp 已就绪，跳过 =="
fi

# ---------------------------------------------------------------- openpyxl

# config-test 的第 4、5 步要用它改 xlsx。装在用户目录（--user），不碰系统 Python。
# 24.04 的 pip 有 PEP 668 保护，要先试普通装法、不行再加 --break-system-packages。
if ! python3 -c "import openpyxl" > /dev/null 2>&1; then
    echo
    echo "== openpyxl =="
    python3 -m pip install --user openpyxl > /tmp/openpyxl-install.log 2>&1 \
        || python3 -m pip install --user --break-system-packages openpyxl >> /tmp/openpyxl-install.log 2>&1
    echo "  完成"
fi

# ---------------------------------------------------------------- 收尾

echo
echo "== 自检 =="
# 装完再跑一次 --check，而不是"装过就算成功"：
# 少装一个扩展时前面每步都返回 0，只有这一遍能抓住
if ! bash "$ROOT/scripts/ci-setup.sh" --check; then
    echo
    echo "环境装完但自检没过，见上"
    exit 1
fi
bash "$ROOT/scripts/ci-preflight.sh" || true
echo
echo "完成。下一步："
echo "  bash scripts/dev-redis.sh && bash scripts/dev-mysql.sh && bash scripts/dev-pg.sh"
echo "  TSF_PHP=php85 TPC=\$HOME/.tsf/toolchain/typephp/bin/tpc.php ./tsf/bin/tsf verify"
