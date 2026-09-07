#!/usr/bin/env bash
set -euo pipefail

readonly real_workspace=/workspace
readonly output_dir="${TYPEPHP_OUTPUT_DIR:-dist}"
readonly output_name="${TYPEPHP_OUTPUT_NAME:-webman-server}"
readonly force="${TYPEPHP_FORCE:-0}"
readonly build_id="$(date -u +%Y%m%dT%H%M%SZ)-$$"

validate_relative_path() {
    local path="$1" segment
    path="${path//\\//}"
    [[ -n "$path" && "$path" != /* && ! "$path" =~ ^[A-Za-z]: ]] || return 1
    IFS='/' read -r -a segments <<< "$path"
    for segment in "${segments[@]}"; do
        [[ -n "$segment" && "$segment" != '.' && "$segment" != '..' ]] || return 1
    done
    [[ "${segments[0]}" != '.typephp' ]]
}

copy_file() {
    local source="$1" target="$2"
    mkdir -p "$(dirname "$target")"
    cp -a "$source" "$target"
}

copy_linked_library() {
    local library="$1" target="$stage_dir/lib/$(basename "$library")"
    [[ -f "$library" ]] || return 1
    mkdir -p "$stage_dir/lib"
    cp -L "$library" "$target"
}

is_platform_library() {
    case "$(basename "$1")" in
        libc.so*|ld-linux*.so*|libm.so*|libpthread.so*|libdl.so*|librt.so*|libstdc++.so*|libgcc_s.so*) return 0 ;;
        *) return 1 ;;
    esac
}

is_allowed_library() {
    case "$(basename "$1")" in
        libphpx.so*|libphp.so*|libgmp.so*|libmpfr.so*|libpcre2-8.so*|libz.so*|libssl.so*|libcrypto.so*|libcurl.so*|libxml2.so*|libonig.so*|libicu*.so*|libsodium.so*|libargon2.so*|liblzma.so*|*) return 0 ;;
    esac
}

cleanup_stage_on_failure() {
    local exit_code=$?
    if [[ "$exit_code" -ne 0 ]]; then
        rm -rf -- "$stage_dir"
    fi
    exit "$exit_code"
}

if ! validate_relative_path "$output_dir"; then
    echo '[ERROR] TYPEPHP_OUTPUT_DIR must be a safe project-relative path.' >&2
    exit 2
fi
if [[ ! "$output_name" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo '[ERROR] TYPEPHP_OUTPUT_NAME is invalid.' >&2
    exit 2
fi

# The host workspace is a Docker Desktop bind mount served over 9P, whose
# per-file latency makes the compiler's thousands of small reads/writes
# extremely slow (the analysis phase alone can stall for many minutes and the
# single big binary write is cheap by comparison). Copy the project into
# container-native storage, compile there, and copy only the final portable
# directory back to the bind mount at the end.
readonly native_root="/native/$build_id"
mkdir -p "$native_root"
( cd "$real_workspace" && tar \
    --exclude=./.git \
    --exclude=./runtime \
    --exclude=./build \
    --exclude="./$output_dir" \
    --exclude=./.typephp/out-* \
    --exclude=./.typephp/previous-* \
    -cf - . ) | ( cd "$native_root" && tar -xf - )
readonly workspace="$native_root"
readonly build_dir="$workspace/.typephp/build"
readonly stage_dir="$workspace/.typephp/out-$build_id"

cd "$workspace"
project_file="$workspace/project.linux.yml"
[[ -f "$project_file" ]] || project_file="$build_dir/project.linux.yml"
if [[ ! -f "$project_file" ]]; then
    echo '[ERROR] project.linux.yml is missing from the workspace root and .typephp/build.' >&2
    exit 1
fi

# TypePHP does not create the parent directory of the configured output. The
# MVP generator writes a simple top-level scalar, for example
# `output: build/webman-server`; create only its validated parent directory.
project_output="$(awk '/^output:[[:space:]]*/ { sub(/^output:[[:space:]]*/, ""); print; exit }' "$project_file")"
project_output="${project_output#\'}"
project_output="${project_output%\'}"
project_output="${project_output#\"}"
project_output="${project_output%\"}"
if [[ ! "$project_output" =~ ^[A-Za-z0-9._/-]+$ ]] || ! validate_relative_path "$project_output"; then
    echo '[ERROR] project.linux.yml output must be a safe workspace-relative path.' >&2
    exit 2
fi
mkdir -p "$workspace/$(dirname -- "$project_output")"

if [[ -f /opt/typephp/vendor/bin/tpc.php ]]; then
    tpc=(php /opt/typephp/vendor/bin/tpc.php)
elif [[ -x /usr/local/bin/tpc ]]; then
    tpc=(/usr/local/bin/tpc)
else
    echo '[ERROR] TypePHP compiler was not found in the builder image.' >&2
    exit 1
fi

# Parallelize the C++ codegen/compile phase across available cores. job:1 makes
# the ~371-file g++ phase take 40+ minutes (exceeding the command timeout);
# cap at 8 to bound peak memory since each PHPX-template g++ job can be large.
jobs="$(nproc 2>/dev/null || echo 2)"
[[ "$jobs" -gt 8 ]] && jobs=8
[[ "$jobs" -lt 1 ]] && jobs=1
sed -i "s/^job:.*/job: ${jobs}/" "$project_file"

echo "[INFO] Compiling Linux x86_64 glibc binary (job=${jobs})..."
"${tpc[@]}" "$project_file" --no-progress

normalized_output="$(dirname -- "$project_output")/$(basename -- "$project_output" | tr '-' '_')"
if ! validate_relative_path "$normalized_output"; then
    echo '[ERROR] Normalized TypePHP output path is unsafe.' >&2
    exit 2
fi

compiled_bin="$workspace/$project_output"
normalized_compiled_bin="$workspace/$normalized_output"
if [[ ! -f "$compiled_bin" && -f "$normalized_compiled_bin" ]]; then
    compiled_bin="$normalized_compiled_bin"
fi
if [[ ! -f "$compiled_bin" ]]; then
    echo "[ERROR] Compiled executable '$project_output' was not found." >&2
    exit 1
fi

if ! ldd_output="$(LD_LIBRARY_PATH=/opt/typephp/vendor/swoole/phpx/lib:/usr/lib ldd "$compiled_bin" 2>&1)"; then
    echo "[ERROR] ldd failed for '$compiled_bin': $ldd_output" >&2
    exit 1
fi
if grep -q 'not found' <<<"$ldd_output"; then
    echo "[ERROR] Unresolved runtime library: $ldd_output" >&2
    exit 1
fi

mkdir -p "$stage_dir/lib"
trap cleanup_stage_on_failure EXIT
install -m 0755 "$compiled_bin" "$stage_dir/webman-server.bin"

# 1. 自动扫描并打包 PHP 扩展模块 (.so) 到 dist/ext
mkdir -p "$stage_dir/ext"
php_ext_dir="$(php-config --extension-dir 2>/dev/null || echo '/usr/lib/php/20240924')"
if [[ -d "$php_ext_dir" ]]; then
    echo "[INFO] Copying PHP extensions from $php_ext_dir to dist/ext/ ..."
    cp -f "$php_ext_dir"/*.so "$stage_dir/ext/" 2>/dev/null || true
fi

# 2. 自动通过 ldd 探测并打包所有程序、PHP 核心库与全部扩展模块的底层共享库
mkdir -p "$stage_dir/lib"
for bin_or_lib in "$stage_dir/webman-server.bin" "$stage_dir/lib"/*.so* "$stage_dir/ext"/*.so; do
    if [[ -f "$bin_or_lib" ]]; then
        mapfile -t found_libs < <(ldd "$bin_or_lib" 2>/dev/null | awk '/=> \// { print $3 }')
        for libpath in "${found_libs[@]}"; do
            if [[ -f "$libpath" ]]; then
                libname="$(basename "$libpath")"
                if is_platform_library "$libname"; then
                    continue
                fi
                if [[ ! -f "$stage_dir/lib/$libname" ]]; then
                    cp -L "$libpath" "$stage_dir/lib/$libname" || true
                fi
            fi
        done
    fi
done

# 确保核心运行时动态库存在，并复制到根目录和 lib/ 目录
for required_library in 'libphpx.so*' 'libphp.so*'; do
    found_lib="$(find "$stage_dir/lib" /usr -name "$required_library" -type f 2>/dev/null | head -n 1 || true)"
    if [[ -n "$found_lib" && -f "$found_lib" ]]; then
        libname="$(basename "$found_lib")"
        clean_libname="${libname%%.*}.so"
        cp -L "$found_lib" "$stage_dir/$clean_libname" || true
        cp -L "$found_lib" "$stage_dir/lib/$libname" || true
    fi
done

# 3. 修复可执行程序和扩展的 RPATH（如果有 patchelf）
if command -v patchelf &> /dev/null; then
    patchelf --set-rpath '$ORIGIN:$ORIGIN/lib' "$stage_dir/webman-server.bin" 2>/dev/null || true
    for solib in "$stage_dir"/*.so "$stage_dir"/lib/*.so*; do
        [[ -f "$solib" ]] && patchelf --set-rpath '$ORIGIN:$ORIGIN/lib' "$solib" 2>/dev/null || true
    done
    for extsolib in "$stage_dir"/ext/*.so; do
        [[ -f "$extsolib" ]] && patchelf --set-rpath '$ORIGIN/../lib:$ORIGIN:$ORIGIN/..' "$extsolib" 2>/dev/null || true
    done
fi

# 4. 生成自包含纯净 php.ini
cat > "$stage_dir/php.ini" << 'EOF'
output_buffering=0
implicit_flush=1
memory_limit=4G
opcache.enable_cli=0
extension_dir="./ext"

; 核心进程管理与网络扩展
extension=posix.so
extension=pcntl.so
extension=openssl.so
extension=mbstring.so
extension=mysqlnd.so
extension=pdo.so
extension=pdo_mysql.so
extension=mysqli.so
extension=curl.so
extension=fileinfo.so
extension=zip.so
extension=igbinary.so
extension=msgpack.so
extension=redis.so
extension=sockets.so
extension=event.so
EOF

# 若 ext/ 目录下没有对应扩展 .so，则注释该行以防产生 Warning
while IFS= read -r line || [[ -n "$line" ]]; do
    if echo "$line" | grep -q "^extension="; then
        ext_file=$(echo "$line" | cut -d'=' -f2)
        if [[ ! -f "$stage_dir/ext/$ext_file" ]]; then
            sed -i "s/^extension=$ext_file$/; extension=$ext_file/" "$stage_dir/php.ini"
        fi
    fi
done < "$stage_dir/php.ini"

# 5. 生成标准可移植启动包装脚本 webman-server 与 start.sh
install -m 0755 /dev/stdin "$stage_dir/webman-server" <<'SCRIPT'
#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cd "$SCRIPT_DIR"

export PHPRC="$SCRIPT_DIR"
export LD_LIBRARY_PATH="$SCRIPT_DIR:$SCRIPT_DIR/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
exec "$SCRIPT_DIR/webman-server.bin" "$@"
SCRIPT

install -m 0755 /dev/stdin "$stage_dir/start.sh" <<'SCRIPT'
#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

export PHPRC="$SCRIPT_DIR"
export LD_LIBRARY_PATH="$SCRIPT_DIR:$SCRIPT_DIR/lib:$LD_LIBRARY_PATH"

if [ ! -f "$SCRIPT_DIR/webman-server" ]; then
    echo "[ERROR] webman-server executable not found in $SCRIPT_DIR!"
    exit 1
fi

chmod +x "$SCRIPT_DIR/webman-server" 2>/dev/null || true
exec "$SCRIPT_DIR/webman-server" "$@"
SCRIPT

# 6. 复制运行时业务资源并确保 runtime 目录
for resource in config public; do
    [[ -d "$resource" ]] && cp -a "$resource" "$stage_dir/"
done
mkdir -p "$stage_dir/runtime/logs" "$stage_dir/runtime/views"
if [[ -d app/view ]]; then mkdir -p "$stage_dir/app"; cp -a app/view "$stage_dir/app/"; fi
if [[ -f app/functions.php ]]; then copy_file app/functions.php "$stage_dir/app/functions.php"; fi
[[ -f "$build_dir/build-manifest.json" ]] && copy_file "$build_dir/build-manifest.json" "$stage_dir/build-manifest.json"

readonly final_dir="$real_workspace/$output_dir"
if [[ -e "$final_dir" ]]; then
    if [[ "$force" != 1 ]]; then
        echo "[ERROR] Output '$output_dir' exists; use --force to replace it." >&2
        exit 1
    fi
    mv "$final_dir" "$real_workspace/.typephp/previous-$build_id"
fi
# stage_dir is on native storage while final_dir is on the 9P bind mount, so this
# is a cross-device copy of the finished portable directory back to the host.
cp -a "$stage_dir" "$final_dir"
trap - EXIT
echo "[SUCCESS] Portable directory created: $output_dir (run ./start.sh from it)"
