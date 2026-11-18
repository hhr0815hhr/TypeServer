#!/bin/bash
# 安装 tsf 工具链（typephp 编译器）到 ~/.tsf/toolchain/。
#
# ## 它装什么、不装什么
#
# 装：typephp 的 bin + src + vendor（工具链包，解压约 168MB）
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
#   TSF_TOOLCHAIN_URL      完整下载地址，优先于下面两项
#   TSF_TOOLCHAIN_BASE     发布地址前缀（默认 GitHub Releases）
#   TSF_TOOLCHAIN_VERSION  版本（默认 dev，和打包脚本的 TYPEPHP_VER 对应）
#   TSF_TOOLCHAIN_DIR      安装目录（默认 ~/.tsf/toolchain）

set -euo pipefail

OS="$(uname -s | tr '[:upper:]' '[:lower:]')"
ARCH="$(uname -m)"
case "$ARCH" in
    x86_64|amd64) ARCH="x86_64" ;;
    aarch64|arm64) ARCH="arm64" ;;
esac

DIR="${TSF_TOOLCHAIN_DIR:-$HOME/.tsf/toolchain}"
VER="${TSF_TOOLCHAIN_VERSION:-v0.7.0}"

if [ -n "${TSF_TOOLCHAIN_URL:-}" ]; then
    URL="$TSF_TOOLCHAIN_URL"
else
    BASE="${TSF_TOOLCHAIN_BASE:-https://github.com/hhr0815hhr/TypeServer/releases}"
    URL="$BASE/download/$VER/tsf-toolchain-$OS-$ARCH-$VER.tar.gz"
    # 提示：release 的 tag 必须和包文件名里的版本一致
fi

echo "平台    : $OS-$ARCH"
echo "版本    : $VER"
echo "安装到  : $DIR"
echo "来源    : $URL"
echo

mkdir -p "$DIR"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "== 下载 =="
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
        if ! curl -fL --progress-bar -o "$TMP/pkg.tar.gz" "$URL"; then
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
