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
        libphpx.so*|libphp.so*|libgmp.so*|libmpfr.so*|libpcre2-8.so*|libz.so*|libssl.so*|libcrypto.so*|libcurl.so*|libxml2.so*|libonig.so*|libicu*.so*|libsodium.so*|libargon2.so*|liblzma.so*) return 0 ;;
        *) return 1 ;;
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
mapfile -t linked_libraries < <(awk '/=> \// { print $3 }' <<<"$ldd_output")
for library in "${linked_libraries[@]}"; do
    if is_platform_library "$library"; then
        continue
    fi
    if ! is_allowed_library "$library"; then
        echo "[ERROR] Runtime library is not in the portable allowlist: $(basename "$library")" >&2
        exit 1
    fi
    copy_linked_library "$library"
done

for required_library in 'libphpx.so*' 'libphp.so*'; do
    if ! find "$stage_dir/lib" -maxdepth 1 -type f -name "$required_library" | grep -q .; then
        echo "[ERROR] The portable directory is missing $required_library after ldd collection." >&2
        exit 1
    fi
done

install -m 0755 /dev/stdin "$stage_dir/start.sh" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
readonly app_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$app_dir"
export LD_LIBRARY_PATH="$app_dir/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
exec "$app_dir/webman-server.bin" "$@"
SCRIPT

for resource in config public; do
    [[ -d "$resource" ]] && cp -a "$resource" "$stage_dir/"
done
if [[ -d app/view ]]; then mkdir -p "$stage_dir/app"; cp -a app/view "$stage_dir/app/"; fi
if [[ -f app/functions.php ]]; then copy_file app/functions.php "$stage_dir/app/functions.php"; fi
if [[ -f vendor/workerman/webman-framework/src/support/helpers.php ]]; then copy_file vendor/workerman/webman-framework/src/support/helpers.php "$stage_dir/vendor/workerman/webman-framework/src/support/helpers.php"; fi
if [[ -d vendor/workerman/workerman/src/Protocols/Http/Session ]]; then mkdir -p "$stage_dir/vendor/workerman/workerman/src/Protocols/Http"; cp -a vendor/workerman/workerman/src/Protocols/Http/Session "$stage_dir/vendor/workerman/workerman/src/Protocols/Http/"; fi
if [[ -f vendor/workerman/workerman/src/Protocols/Http/Session.php ]]; then copy_file vendor/workerman/workerman/src/Protocols/Http/Session.php "$stage_dir/vendor/workerman/workerman/src/Protocols/Http/Session.php"; fi
if [[ -d vendor/workerman/coroutine/src ]]; then mkdir -p "$stage_dir/vendor/workerman/coroutine"; cp -a vendor/workerman/coroutine/src "$stage_dir/vendor/workerman/coroutine/"; fi
if [[ -f vendor/nikic/fast-route/src/functions.php ]]; then copy_file vendor/nikic/fast-route/src/functions.php "$stage_dir/vendor/nikic/fast-route/src/functions.php"; fi
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
