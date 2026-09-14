#!/usr/bin/env bash
set -euo pipefail

dist_dir=${AOT_DIST:-}
binary_name=${AOT_BINARY_NAME:-webman-server.bin}

if [[ -z "$dist_dir" || "$dist_dir" != /* ]]; then
  echo "AOT_DIST must be an absolute portable-dir path." >&2
  exit 64
fi

required=("$binary_name" webman-server start.sh libphp.so libphpx.so php.ini build-manifest.json)
for relative_path in "${required[@]}"; do
  [[ -e "$dist_dir/$relative_path" ]] || {
    echo "Missing required artifact: $relative_path" >&2
    exit 65
  }
done

if [[ ! -d "$dist_dir/ext" || ! -d "$dist_dir/lib" || ! -d "$dist_dir/runtime" ]]; then
  echo "Missing ext/, lib/, or runtime/ directory." >&2
  exit 65
fi

file "$dist_dir/$binary_name" | grep -Eq 'ELF 64-bit.*x86-64' || {
  echo "Native binary is not Linux amd64 ELF." >&2
  exit 66
}

php -r '
  $manifest = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  foreach (["output_name", "builder_image", "built_at"] as $key) {
      if (!isset($manifest[$key]) || $manifest[$key] === "") {
          throw new RuntimeException("Missing manifest key: ".$key);
      }
  }
  if (!isset($manifest["runtime_resources"]) || !is_array($manifest["runtime_resources"])) {
      throw new RuntimeException("Missing manifest runtime_resources.");
  }
  $dist = $argv[2];
  if (($manifest["profile"] ?? null) === "saiadmin") {
      $coverageFile = $dist."/source-coverage.json";
      if (!is_file($coverageFile)) {
          throw new RuntimeException("Missing SaiAdmin source coverage manifest.");
      }
      $coverage = json_decode(file_get_contents($coverageFile), true, 512, JSON_THROW_ON_ERROR);
      if (($coverage["profile"] ?? null) !== "saiadmin" || !is_array($coverage["files"] ?? null)) {
          throw new RuntimeException("Invalid SaiAdmin source coverage manifest.");
      }
      $counts = $coverage["counts"] ?? [];
      if (($counts["compiled"] ?? -1) + ($counts["generated"] ?? -1) !== count($coverage["files"])) {
          throw new RuntimeException("SaiAdmin source coverage counts do not match its file list.");
      }
      if (($manifest["inputs"]["source_coverage_hash"] ?? "") !== sha1_file($coverageFile)) {
          throw new RuntimeException("SaiAdmin source coverage hash mismatch.");
      }
      foreach ($coverage["files"] as $file) {
          if (!is_array($file) || !in_array($file["mode"] ?? null, ["compiled", "generated"], true)) {
              throw new RuntimeException("Invalid SaiAdmin source coverage entry.");
          }
          $path = $file["path"] ?? "";
          if (!is_string($path) || $path === "" || str_starts_with($path, "/") || str_contains($path, "..")) {
              throw new RuntimeException("Unsafe SaiAdmin business source path.");
          }
          if (file_exists($dist."/".$path)) {
              throw new RuntimeException("Business PHP leaked into portable-dir: ".$path);
          }
      }
  }
  foreach ($manifest["runtime_resources"] as $resource) {
      if (!is_string($resource) || $resource === "" || str_starts_with($resource, "/") || str_contains($resource, "..")) {
          throw new RuntimeException("Unsafe runtime resource path.");
      }
      if (!file_exists($dist."/".$resource)) {
          throw new RuntimeException("Missing runtime resource: ".$resource);
      }
  }
' "$dist_dir/build-manifest.json" "$dist_dir"

echo "Portable-dir contract OK: $dist_dir"
