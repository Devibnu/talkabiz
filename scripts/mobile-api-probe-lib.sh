#!/usr/bin/env bash

set -o errexit
set -o nounset
set -o pipefail

mobile_api_require_credentials() {
  BASE_URL="${1:-https://talkabiz.ibnuapps.cloud/api}"
  EMAIL="${2:-}"
  PASSWORD="${3:-}"
  DEVICE_NAME="${4:-Probe CLI}"

  if [[ -z "$EMAIL" || -z "$PASSWORD" ]]; then
    echo "Usage: $0 <base_url> <email> <password> [device_name]" >&2
    exit 1
  fi

  MOBILE_API_TEMP_DIR="$(mktemp -d)"
  trap 'rm -rf "$MOBILE_API_TEMP_DIR"' EXIT
}

mobile_api_request_json() {
  local method="$1"
  local path="$2"
  local body="${3:-}"
  local token="${4:-}"
  local output_file="$5"

  local headers=(-H 'Accept: application/json')

  if [[ -n "$token" ]]; then
    headers+=(-H "Authorization: Bearer $token")
  fi

  if [[ -n "$body" ]]; then
    headers+=(-H 'Content-Type: application/json')
  fi

  if [[ -n "$body" ]]; then
    curl -sS -o "$output_file" -w '%{http_code}' -X "$method" "${BASE_URL}${path}" "${headers[@]}" -d "$body"
  else
    curl -sS -o "$output_file" -w '%{http_code}' -X "$method" "${BASE_URL}${path}" "${headers[@]}"
  fi
}

mobile_api_print_section() {
  local title="$1"
  local file="$2"

  printf '\n[%s]\n' "$title"
  cat "$file"
  printf '\n'
}

mobile_api_assert_status() {
  local actual="$1"
  local expected="$2"
  local label="$3"

  if [[ "$actual" != "$expected" ]]; then
    echo "FAILED: $label returned HTTP $actual, expected $expected" >&2
    exit 1
  fi
}

mobile_api_json_get() {
  local file="$1"
  local path="$2"

  php -r '
    $data = json_decode(file_get_contents($argv[1]), true);
    $segments = explode(".", $argv[2]);
    $current = $data;
    foreach ($segments as $segment) {
        if ($segment === "") {
            continue;
        }
        if (is_array($current) && array_key_exists($segment, $current)) {
            $current = $current[$segment];
            continue;
        }
        if (ctype_digit($segment) && is_array($current) && array_key_exists((int) $segment, $current)) {
            $current = $current[(int) $segment];
            continue;
        }
        exit(0);
    }
    if (is_array($current)) {
        echo json_encode($current);
    } elseif ($current !== null) {
        echo $current;
    }
  ' "$file" "$path"
}

mobile_api_login() {
  local login_file="$MOBILE_API_TEMP_DIR/login.json"
  local login_body
  local login_status

  login_body=$(printf '{"email":"%s","password":"%s","device_name":"%s"}' "$EMAIL" "$PASSWORD" "$DEVICE_NAME")
  login_status=$(mobile_api_request_json POST '/mobile/auth/login' "$login_body" '' "$login_file")
  mobile_api_assert_status "$login_status" 200 'Login'
  mobile_api_print_section 'LOGIN' "$login_file"

  MOBILE_API_TOKEN="$(mobile_api_json_get "$login_file" 'data.token')"

  if [[ -z "$MOBILE_API_TOKEN" ]]; then
    echo 'FAILED: Login succeeded but token is missing.' >&2
    exit 1
  fi
}

mobile_api_logout() {
  local logout_file="$MOBILE_API_TEMP_DIR/logout.json"
  local logout_status

  logout_status=$(mobile_api_request_json POST '/mobile/auth/logout' '{}' "$MOBILE_API_TOKEN" "$logout_file")
  mobile_api_assert_status "$logout_status" 200 'Logout'
  mobile_api_print_section 'LOGOUT' "$logout_file"
}