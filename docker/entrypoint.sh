#!/usr/bin/env bash
set -euo pipefail

echo "=========================================================="
echo " [TypePHP Builder] Starting Portable-Dir Compilation"
echo "=========================================================="

WORKSPACE_DIR="/workspace"
BUILD_DIR="$WORKSPACE_DIR/.typephp/build"
OUTPUT_DIR="${TYPEPHP_OUTPUT_DIR:-dist}"
OUTPUT_NAME="${TYPEPHP_OUTPUT_NAME:-webman-server}"

# 安全校验环境变量
case "$OUTPUT_DIR" in ''|/*) echo "[ERROR] Illegal output dir: $OUTPUT_DIR"; exit 2;; esac
IFS='/' read -r -a output_parts <<< "$OUTPUT_DIR"
for part in "${output_parts[@]}"; do
  case "$part" in ''|.|..) echo "[ERROR] Illegal output dir part: $part"; exit 2;; esac
done
if [ "${output_parts[0]}" = '.typephp' ]; then
  echo "[ERROR] Output dir cannot be within .typephp"; exit 2
fi
case "$OUTPUT_NAME" in ''|*[!A-Za-z0-9._-]*) echo "[ERROR] Illegal output name: $OUTPUT_NAME"; exit 2;; esac

PROJECT_FILE="$WORKSPACE_DIR/project.linux.yml"
if [ ! -f "$PROJECT_FILE" ]; then
    PROJECT_FILE="$BUILD_DIR/project.linux.yml"
fi

cd "$WORKSPACE_DIR"

if [ ! -f "$PROJECT_FILE" ]; then
    echo "[ERROR] project.linux.yml not found in workspace root or .typephp/build/!"
    exit 1
fi

TPC_PATH="/opt/typephp/bin/tpc.php"
if [ ! -f "$TPC_PATH" ]; then
    if [ -f "./vendor/bin/tpc.php" ]; then
        TPC_PATH="./vendor/bin/tpc.php"
    elif command -v tpc &> /dev/null; then
        TPC_PATH=$(command -v tpc)
    else
        echo "[ERROR] TypePHP compiler (tpc) not found in /opt/typephp or PATH!"
        exit 1
    fi
fi

echo "[INFO] Running TypePHP compiler on $PROJECT_FILE ..."
php "$TPC_PATH" "$PROJECT_FILE"

ALT_OUTPUT_NAME="${OUTPUT_NAME//-/_}"

# 查找生成的二进制（TypePHP 会将连字符转为下划线，如 webman-server -> webman_server）
COMPILED_BIN=""
for cand in \
    "$BUILD_DIR/compiler/$OUTPUT_NAME" "$BUILD_DIR/compiler/$ALT_OUTPUT_NAME" \
    "build/$OUTPUT_NAME" "build/$ALT_OUTPUT_NAME" \
    "$BUILD_DIR/$OUTPUT_NAME" "$BUILD_DIR/$ALT_OUTPUT_NAME"; do
    if [ -f "$cand" ]; then
        COMPILED_BIN="$cand"
        break
    fi
done

if [ -z "$COMPILED_BIN" ]; then
    FOUND_BIN=$(find build .typephp -maxdepth 3 -type f \( -name "$OUTPUT_NAME" -o -name "$ALT_OUTPUT_NAME" \) 2>/dev/null | head -n 1 || true)
    if [ -n "$FOUND_BIN" ] && [ -f "$FOUND_BIN" ]; then
        COMPILED_BIN="$FOUND_BIN"
    fi
fi

if [ -z "$COMPILED_BIN" ] || [ ! -f "$COMPILED_BIN" ]; then
    echo "[ERROR] Compiled executable $OUTPUT_NAME not found after tpc compilation!"
    exit 1
fi

BUILD_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
STAGE_DIR="$WORKSPACE_DIR/.typephp/out-$BUILD_ID"
mkdir -p "$STAGE_DIR"

install -m 0755 "$COMPILED_BIN" "$STAGE_DIR/$OUTPUT_NAME"
if [ -f "$BUILD_DIR/build-manifest.json" ]; then
    install -m 0644 "$BUILD_DIR/build-manifest.json" "$STAGE_DIR/build-manifest.json"
fi

[ -d config ] && cp -a config "$STAGE_DIR/config"
[ -d public ] && cp -a public "$STAGE_DIR/public"
if [ -d app/view ]; then
    mkdir -p "$STAGE_DIR/app"
    cp -a app/view "$STAGE_DIR/app/view"
fi
if [ -f app/functions.php ]; then
    mkdir -p "$STAGE_DIR/app"
    cp -a app/functions.php "$STAGE_DIR/app/functions.php"
fi
if [ -f vendor/workerman/webman-framework/src/support/helpers.php ]; then
    mkdir -p "$STAGE_DIR/vendor/workerman/webman-framework/src/support"
    cp -a vendor/workerman/webman-framework/src/support/helpers.php "$STAGE_DIR/vendor/workerman/webman-framework/src/support/helpers.php"
fi
if [ -d vendor/workerman/workerman/src/Protocols/Http/Session ]; then
    mkdir -p "$STAGE_DIR/vendor/workerman/workerman/src/Protocols/Http"
    cp -a vendor/workerman/workerman/src/Protocols/Http/Session "$STAGE_DIR/vendor/workerman/workerman/src/Protocols/Http/"
fi
if [ -f vendor/workerman/workerman/src/Protocols/Http/Session.php ]; then
    mkdir -p "$STAGE_DIR/vendor/workerman/workerman/src/Protocols/Http"
    cp -a vendor/workerman/workerman/src/Protocols/Http/Session.php "$STAGE_DIR/vendor/workerman/workerman/src/Protocols/Http/Session.php"
fi
if [ -d vendor/workerman/coroutine/src ]; then
    mkdir -p "$STAGE_DIR/vendor/workerman/coroutine"
    cp -a vendor/workerman/coroutine/src "$STAGE_DIR/vendor/workerman/coroutine/"
fi

FINAL_DIR="$WORKSPACE_DIR/$OUTPUT_DIR"
if [ -e "$FINAL_DIR" ]; then
    if [ "${TYPEPHP_FORCE:-0}" != '1' ]; then
        echo "[ERROR] Target directory $FINAL_DIR exists and TYPEPHP_FORCE != 1"
        rm -rf "$STAGE_DIR"
        exit 1
    fi
    mv "$FINAL_DIR" "$WORKSPACE_DIR/.typephp/previous-$BUILD_ID"
fi

mv "$STAGE_DIR" "$FINAL_DIR"
echo "=========================================================="
echo " [SUCCESS] Portable artifact created at: $OUTPUT_DIR/$OUTPUT_NAME"
echo "=========================================================="
