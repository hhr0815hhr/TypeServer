#!/bin/bash
# 安装 tsf 工具链（typephp 编译器）到 ~/.tsf/toolchain/。
#
# ## 版本号说的是"编译器"，不是框架
#
# 工具链有自己的版本（当前 typephp **0.9.6**），发布在**独立 tag**
# `toolchain-0.9.6` 下。以前它借用框架的 `v0.7.0` tag，于是 release 页面上
# "v0.7.0" 既是框架 0.7.0 的源码、又是工具链资产，而框架早已 0.17.0——
# 只看页面的人会以为最新版就是 0.7。
#
# 同一份工具链可以配任意框架版本（编译器只在 PHP / swoole 的 ABI 变化时才需要动），
# 所以两者的版本号本来就不该同步。
#
# ## 它装什么、不装什么
#
# 装：typephp 的 bin + src + vendor（工具链包，解压约 172MB）
# 不装：PHP 8.5 ZTS、clang、libgmp —— 这些必须用系统包管理器装，脚本不碰
#
# 所以装完之后还要跑一次 `tsf doctor`，它会告诉你剩下缺什么。
#
# ## 用法
#
#   # 从 GitHub Releases 下载（默认）
#   curl -fsSL https://.../install-toolchain.sh | bash
#
#   # 用本地已经打好的包（离线/内网）
#   TSF_TOOLCHAIN_URL=file:///path/to/tsf-toolchain-linux-x86_64-dev.tar.gz \
#     bash scripts/install-toolchain.sh
#
#   # 装到别处（CI 里常这么用）
#   TSF_TOOLCHAIN_DIR=/opt/tsf bash scripts/install-toolchain.sh
#
# 环境变量：
#   TSF_TOOLCHAIN_TAG      GitHub tag（默认 toolchain-0.9.6；换编译器版本时改这里）
#   TSF_TOOLCHAIN_URL      完整下载地址，优先于其余
#   TSF_TOOLCHAIN_BASE     发布地址前缀（默认 GitHub Releases）
#   TSF_TOOLCHAIN_VERSION  旧变量，当作 tag 用；保留兼容老脚本
#   TSF_TOOLCHAIN_DIR      安装目录（默认 ~/.tsf/toolchain）

set -euo pipefail

OS="$(uname -s | tr '[:upper:]' '[:lower:]')"
ARCH="$(uname -m)"
case "$ARCH" in
    x86_64|amd64) ARCH="x86_64" ;;
    aarch64|arm64) ARCH="arm64" ;;
esac

DIR="${TSF_TOOLCHAIN_DIR:-$HOME/.tsf/toolchain}"

# tag 与资产里的版本是两回事：
#   tag = toolchain-0.9.6   （GitHub release 的位置）
#   资产 = ...-0.9.6.tar.gz （文件名里的版本）
# 旧 tag（v0.7.0）两者相同，所以下面这个映射对两种命名都成立。
if [ -n "${TSF_TOOLCHAIN_TAG:-}" ]; then
    TAG="$TSF_TOOLCHAIN_TAG"
elif [ -n "${TSF_TOOLCHAIN_VERSION:-}" ]; then
    TAG="$TSF_TOOLCHAIN_VERSION"
else
    TAG="toolchain-0.9.6"
fi
case "$TAG" in
    toolchain-*) VER="${TAG#toolchain-}" ;;
    *)           VER="$TAG" ;;
esac

if [ -n "${TSF_TOOLCHAIN_URL:-}" ]; then
    URL="$TSF_TOOLCHAIN_URL"
else
    # 默认走 tsf 仓库的 GitHub Release。
    #
    # 注意 42MB 的包**不能用网页上传**（限 25MB），发布时必须用
    # `gh release create` 或 API（支持 2GB）。这里只是下载，不限。
    #
    # 国内网络如果拉不动 github.com，用 TSF_TOOLCHAIN_URL 指镜像：
    #   TSF_TOOLCHAIN_URL=https://your-mirror/tsf-toolchain-linux-x86_64-0.9.6.tar.gz
    BASE="${TSF_TOOLCHAIN_BASE:-https://github.com/hhr0815hhr/tsf/releases/download/$TAG}"
    URL="$BASE/tsf-toolchain-$OS-$ARCH-$VER.tar.gz"
fi

# 只打印解析结果不下载：CI 里校验地址拼接、排障时确认"到底要拉哪个包"
if [ "${TSF_TOOLCHAIN_DRY_RUN:-0}" = "1" ]; then
    echo "平台    : $OS-$ARCH"
    echo "tag     : $TAG（工具链版本 $VER，与框架版本无关）"
    echo "安装到  : $DIR"
    echo "来源    : $URL"
    exit 0
fi

echo "平台    : $OS-$ARCH"
echo "tag     : $TAG（工具链版本 $VER，与框架版本无关）"
echo "安装到  : $DIR"
echo "来源    : $URL"
echo

# tsf 只能在 Linux 上跑：Swoole 的 Server 依赖 Unix Socket 与 fork，
# 产物还要链 ELF 的 libphpx.so。在 Windows 的 Git Bash 里跑这条命令会
# 拼出一个 mingw64 的包名，下载失败后报错完全看不出原因。
if [ "$OS" != "linux" ]; then
    echo "不支持的系统: $OS" >&2
    echo "  tsf 只能在 Linux / WSL2 / 容器里编译运行。" >&2
    echo "  Windows 请进 WSL 再跑：wsl -- bash scripts/install-toolchain.sh" >&2
    exit 1
fi

mkdir -p "$DIR"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "== 下载 =="
# 通过 `curl ... | bash` 调用时 stdout 不是 tty，curl 不会显示进度条 ——
# 用户会以为卡住了。所以先报一句体量，让等待有预期。
echo "  包大小约 42MB（解压 172MB），正常网速 1-2 分钟"
case "$URL" in
    file://*)
        # 本地文件：直接 cp，顺便验证路径存在
        SRC="${URL#file://}"
        if [ ! -f "$SRC" ]; then
            echo "本地包不存在: $SRC" >&2
            exit 1
        fi
        cp "$SRC" "$TMP/pkg.tar.gz"
        ;;
    *)
        # 进度条写到 stderr。直接跑（stderr 是终端）时保留，方便看进度；
        # 通过 `| grep` / `> log` 重定向时静默 —— 否则输出会被几十 KB 的进度字符刷爆。
        if [ -t 2 ]; then
            curl_flags="-fL"
        else
            curl_flags="-fsL"
        fi
        if ! curl $curl_flags -o "$TMP/pkg.tar.gz" "$URL"; then
            echo >&2
            echo "下载失败。" >&2
            echo "  1. 确认这个地址存在: $URL" >&2
            echo "  2. 或手工下载后用本地安装：" >&2
            echo "     TSF_TOOLCHAIN_URL=file:///path/to/pkg.tar.gz $0" >&2
            exit 1
        fi
        ;;
esac

echo "== 解压 =="
# 包内顶层是 tsf-toolchain-<os>-<arch>-<ver>/，strip 掉一层
if ! tar -xzf "$TMP/pkg.tar.gz" -C "$DIR" --strip-components=1; then
    echo "解压失败（包损坏？）" >&2
    exit 1
fi

TPC="$DIR/typephp/bin/tpc.php"
if [ ! -f "$TPC" ]; then
    echo "解压后没找到 tpc: $TPC" >&2
    echo "  目录内容：" >&2
    ls -1 "$DIR" >&2 || true
    exit 1
fi

echo
echo "已安装到 $DIR"
echo
echo "把这两行加到 shell 配置里（~/.bashrc 或 ~/.zshrc）："
echo "  export TPC=$TPC"
echo "  export TSF_PHP=php85"
echo
echo "如果 tsf 装在项目里（composer require），也可以用："
echo "  TPC=$TPC ./vendor/bin/tsf doctor"
echo
echo "下一步：跑 tsf doctor 看看还缺什么（PHP 8.5 ZTS / clang 这些不在包里）"
