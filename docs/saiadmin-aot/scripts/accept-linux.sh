#!/usr/bin/env bash
set -euo pipefail

if [[ ${AOT_ACCEPT_ISOLATED:-} != YES ]]; then
  echo "Refusing to start: set AOT_ACCEPT_ISOLATED=YES only for an isolated test environment." >&2
  exit 64
fi

dist_dir=${AOT_DIST:-}
base_url=${AOT_BASE_URL:-}
captcha_path=${AOT_CAPTCHA_PATH:-}
login_path=${AOT_LOGIN_PATH:-}
login_body_file=${AOT_LOGIN_BODY_FILE:-}
login_status=${AOT_LOGIN_EXPECT_STATUS:-200}
token_path=${AOT_TOKEN_PATH:-data.access_token}
user_info_path=${AOT_USER_INFO_PATH:-}
user_info_status=${AOT_USER_INFO_EXPECT_STATUS:-200}
denied_path=${AOT_DENIED_PATH:-}
denied_status=${AOT_DENIED_EXPECT_STATUS:-200}
denied_code=${AOT_DENIED_EXPECT_CODE:-400}

for required_value in "$dist_dir" "$base_url" "$captcha_path" "$login_path" "$login_body_file" "$user_info_path" "$denied_path"; do
  [[ -n "$required_value" ]] || {
    echo "Missing required AOT acceptance environment variable." >&2
    exit 64
  }
done

if [[ "$dist_dir" != /* || "$login_body_file" != /* || ! -f "$login_body_file" ]]; then
  echo "AOT_DIST and AOT_LOGIN_BODY_FILE must be valid absolute paths." >&2
  exit 64
fi

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
AOT_DIST="$dist_dir" "$script_dir/verify-package.sh"

response_dir=$(mktemp -d)
cleanup() {
  "$dist_dir/start.sh" stop >/dev/null 2>&1 || true
  rm -rf -- "$response_dir"
}
trap cleanup EXIT INT TERM

(cd "$dist_dir" && ./start.sh start)

ready=0
for _ in {1..30}; do
  if curl --fail --silent --show-error "$base_url" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[[ $ready -eq 1 ]] || {
  echo "Service did not become ready within 30 seconds." >&2
  exit 70
}

actual_captcha_status=$(curl --silent --show-error --output "$response_dir/captcha.json" \
  --write-out '%{http_code}' "$base_url$captcha_path")
[[ "$actual_captcha_status" == 200 ]] || {
  echo "Captcha status mismatch: expected 200, got $actual_captcha_status." >&2
  exit 71
}
php -r '
  $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  $uuid = $value["data"]["uuid"] ?? null;
  $image = $value["data"]["image"] ?? null;
  if (($value["code"] ?? null) !== 200 || !is_string($uuid) || $uuid === ""
      || !is_string($image) || !str_starts_with($image, "data:image/")) {
      exit(2);
  }
' "$response_dir/captcha.json" || {
  echo "Captcha response contract failed." >&2
  exit 71
}

actual_login_status=$(curl --silent --show-error --output "$response_dir/login.json" \
  --write-out '%{http_code}' --header 'Content-Type: application/json' \
  --data-binary "@$login_body_file" "$base_url$login_path")
[[ "$actual_login_status" == "$login_status" ]] || {
  echo "Login status mismatch: expected $login_status, got $actual_login_status." >&2
  exit 71
}

token=$(php -r '
  $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  foreach (explode(".", $argv[2]) as $key) {
      if (!is_array($value) || !array_key_exists($key, $value)) {
          exit(2);
      }
      $value = $value[$key];
  }
  if (!is_string($value) || $value === "") {
      exit(3);
  }
  echo $value;
' "$response_dir/login.json" "$token_path") || {
  echo "Login token not found at JSON path: $token_path." >&2
  exit 71
}

actual_user_info_status=$(curl --silent --show-error --output "$response_dir/user-info.json" \
  --write-out '%{http_code}' --header "Authorization: Bearer $token" "$base_url$user_info_path")
[[ "$actual_user_info_status" == "$user_info_status" ]] || {
  echo "User-info status mismatch: expected $user_info_status, got $actual_user_info_status." >&2
  exit 72
}
php -r '
  $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (($value["code"] ?? null) !== 200 || !is_array($value["data"] ?? null)) {
      exit(2);
  }
' "$response_dir/user-info.json" || {
  echo "User-info response contract failed." >&2
  exit 72
}

actual_denied_status=$(curl --silent --show-error --output "$response_dir/denied.json" \
  --write-out '%{http_code}' --header "Authorization: Bearer $token" "$base_url$denied_path")
[[ "$actual_denied_status" == "$denied_status" ]] || {
    echo "Permission denial mismatch: expected $denied_status, got $actual_denied_status." >&2
    exit 72
}
php -r '
  $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (($value["code"] ?? null) !== (int) $argv[2]) {
      exit(2);
  }
' "$response_dir/denied.json" "$denied_code" || {
  echo "Permission denial JSON code mismatch: expected $denied_code." >&2
  exit 72
}

echo "Isolated captcha, login, user-info, and permission-denial acceptance passed."
