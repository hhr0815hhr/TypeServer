#!/usr/bin/env bash
# 运行交付包里的示例。
#
# 注意：.cwasm 产物**不随代码分发**——它与 wasmtime 版本、编译期特性（fuel/epoch）绑定，
# 必须在目标环境用 precompile.sh 重新生成（manifest 会校验一致性）。
# 本脚本只是把现有产物路径传给示例程序。
set -euo pipefail

KIT=$(cd "$(dirname "$0")" && pwd)

: "${TP_ART_V1:=$KIT/../spike/s1/s1-probe.wasm.cwasm}"
: "${TP_ART_V2:=$KIT/../spike/e1/module_v2/s1-probe-v2.wasm.cwasm}"

for f in "$TP_ART_V1" "$TP_ART_V2"; do
  if [ ! -f "$f" ]; then
    echo "缺少产物：$f" >&2
    echo "请先预编译（见 INTEGRATION.md 第 3 步），或设置 TP_ART_V1 / TP_ART_V2 指向已有产物。" >&2
    exit 1
  fi
  if [ ! -f "$f.manifest" ]; then
    echo "缺少 manifest：$f.manifest（运行期会因校验失败拒绝启动）" >&2
    exit 1
  fi
done

[ -x "$KIT/build/kit_demo" ] || { echo "先执行 ./build.sh" >&2; exit 1; }

export TP_ART_V1 TP_ART_V2
exec "$KIT/build/kit_demo"
