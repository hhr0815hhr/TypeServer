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
    # 默认走 tsf 仓库的 toolchain 分支（raw 链接）。
    #
    # 为什么不用 release 附件：GitHub 的 release **网页上传**限 25MB，这个包 42MB，
    # 只能用 `gh release create` 或 API 上传（支持 2GB）。等 release 建好之后，
    # 用 TSF_TOOLCHAIN_URL 指定完整地址即可（比拼 BASE 更不容易出错）：
    #   TSF_TOOLCHAIN_URL=https://github.com/hhr0815hhr/tsf/releases/download/v0.7.0/tsf-toolchain-linux-x86_64-v0.7.0.tar.gz
    #
    # 包放在 tsf 仓库的独立分支上（不是 main），main 的 clone 体积不受影响。
    BASE="${TSF_TOOLCHAIN_BASE:-https://raw.githubusercontent.com/hhr0815hhr/tsf/toolchain}"
    URL="$BASE/tsf-toolchain-$OS-$ARCH-$VER.tar.gz"
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
# 通过 `curl ... | bash` 调用时 stdout 不是 tty，curl 不会显示进度条 ——
# 用户会以为卡住了。所以先报一句体量，让等待有预期。
echo "  包大小约 42MB（解压 168MB），正常网速 1-2 分钟"
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
        # 不加 --progress-bar：curl 默认只在终端里显示进度，管道重定向时不刷屏。
        # 之前加了 --progress-bar，结果 `| grep` 一下就是 50KB 的进度字符。
        if ! curl -fL -o "$TMP/pkg.tar.gz" "$URL"; then
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
