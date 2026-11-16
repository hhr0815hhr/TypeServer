#!/usr/bin/env bash
# E1 发版：.wasm → .cwasm + manifest，以及产物校验
#
#   ./precompile.sh build  <input.wasm> [output.cwasm]
#   ./precompile.sh verify <input.wasm> <cwasm>
#
# build  ：预编译并写 manifest（指纹 / 尺寸 / sha256）
# verify ：CI 或部署前校验——产物是否与源文件、指纹一致（防止"改了 wasm 忘了重编"）
#
# 注意：fuel / epoch 属于编译期插桩，预编译时的配置必须与运行期一致。
#      本脚本与宿主共用同一份 build_config()，只要环境变量一致就对得上。
set -euo pipefail

MODE=${1:?用法: precompile.sh build|verify <input.wasm> [output.cwasm]}
TOOL=/data/TypeServer/spike/s2/host-capi/target/release/tp-precompile

case "$MODE" in
  build)
    IN=${2:?需要 input.wasm}
    OUT=${3:-$IN.cwasm}
    [ -x "$TOOL" ] || { echo "找不到预编译工具: $TOOL" >&2; exit 1; }

    echo "== 预编译 =="
    echo "  输入: $IN"
    echo "  输出: $OUT"
    "$TOOL" "$IN" "$OUT"

    SRC_SHA=$(sha256sum "$IN" | awk '{print $1}')
    OUT_SHA=$(sha256sum "$OUT" | awk '{print $1}')
    {
      echo "source_sha256=$SRC_SHA"
      echo "artifact_sha256=$OUT_SHA"
      echo "built_at=$(date -Iseconds)"
    } >> "$OUT.manifest"

    echo
    echo "== manifest =="
    cat "$OUT.manifest"
    echo
    echo "完成。运行期加载 $OUT 时会校验 fingerprint 与 artifact_size，不匹配直接拒绝启动。"
    ;;

  verify)
    IN=${2:?需要 input.wasm}
    OUT=${3:?需要 output.cwasm}
    MF=$OUT.manifest

    [ -f "$OUT" ] || { echo "FAIL: 缺少产物 $OUT" >&2; exit 1; }
    [ -f "$MF" ]  || { echo "FAIL: 缺少 manifest $MF" >&2; exit 1; }

    DECLARED_SRC=$(sed -n 's/^source_sha256=//p' "$MF")
    DECLARED_OUT=$(sed -n 's/^artifact_sha256=//p' "$MF")
    ACTUAL_SRC=$(sha256sum "$IN" | awk '{print $1}')
    ACTUAL_OUT=$(sha256sum "$OUT" | awk '{print $1}')

    FAIL=0
    if [ "$DECLARED_SRC" != "$ACTUAL_SRC" ]; then
      echo "FAIL: 源文件已变更，产物陈旧（改了 .wasm 但没重新预编译）"
      echo "  manifest: $DECLARED_SRC"
      echo "  实际    : $ACTUAL_SRC"
      FAIL=1
    fi
    if [ "$DECLARED_OUT" != "$ACTUAL_OUT" ]; then
      echo "FAIL: 产物被改动或损坏"
      echo "  manifest: $DECLARED_OUT"
      echo "  实际    : $ACTUAL_OUT"
      FAIL=1
    fi

    if [ "$FAIL" = "0" ]; then
      echo "OK: 产物与源文件一致"
      sed -n 's/^fingerprint=/  fingerprint=/p; s/^built_at=/  built_at=/p' "$MF"
    fi
    exit "$FAIL"
    ;;

  *)
    echo "未知模式: $MODE" >&2
    exit 2
    ;;
esac
