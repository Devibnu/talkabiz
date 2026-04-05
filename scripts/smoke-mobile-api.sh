#!/usr/bin/env bash

set -o errexit
set -o nounset
set -o pipefail

BASE_URL="${1:-https://talkabiz.ibnuapps.cloud/api}"
EMAIL="${2:-}"
PASSWORD="${3:-}"
DEVICE_NAME="${4:-Smoke Test CLI}"

if [[ -z "$EMAIL" || -z "$PASSWORD" ]]; then
  echo "Usage: $0 <base_url> <email> <password> [device_name]" >&2
  echo "Example: $0 https://talkabiz.ibnuapps.cloud/api basic1@gmail.com ReviewMeta2026!" >&2
  exit 1
fi

temp_dir="$(mktemp -d)"
trap 'rm -rf "$temp_dir"' EXIT

request_json() {
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

  local status

  if [[ -n "$body" ]]; then
    status=$(curl -sS -o "$output_file" -w '%{http_code}' -X "$method" "${BASE_URL}${path}" "${headers[@]}" -d "$body")
  else
    status=$(curl -sS -o "$output_file" -w '%{http_code}' -X "$method" "${BASE_URL}${path}" "${headers[@]}")
  fi

  echo "$status"
}

print_section() {
  local title="$1"
  local file="$2"
  printf '\n[%s]\n' "$title"
  cat "$file"
  printf '\n'
}

assert_status() {
  local actual="$1"
  local expected="$2"
  local label="$3"

  if [[ "$actual" != "$expected" ]]; then
    echo "FAILED: $label returned HTTP $actual, expected $expected" >&2
    exit 1
  fi
}

login_body=$(printf '{"email":"%s","password":"%s","device_name":"%s"}' "$EMAIL" "$PASSWORD" "$DEVICE_NAME")
login_file="$temp_dir/login.json"
login_status=$(request_json POST '/mobile/auth/login' "$login_body" '' "$login_file")
assert_status "$login_status" 200 'Login'
print_section 'LOGIN' "$login_file"

token=$(php -r '$data=json_decode(file_get_contents($argv[1]), true); echo $data["data"]["token"] ?? "";' "$login_file")

if [[ -z "$token" ]]; then
  echo 'FAILED: Login succeeded but token is missing.' >&2
  exit 1
fi

me_file="$temp_dir/me.json"
me_status=$(request_json GET '/mobile/auth/me' '' "$token" "$me_file")
assert_status "$me_status" 200 'Auth me'
print_section 'ME' "$me_file"

dashboard_file="$temp_dir/dashboard.json"
dashboard_status=$(request_json GET '/mobile/dashboard' '' "$token" "$dashboard_file")
assert_status "$dashboard_status" 200 'Dashboard'
print_section 'DASHBOARD' "$dashboard_file"

contacts_file="$temp_dir/contacts.json"
contacts_status=$(request_json GET '/mobile/contacts' '' "$token" "$contacts_file")
assert_status "$contacts_status" 200 'Contacts'
print_section 'CONTACTS' "$contacts_file"

inbox_file="$temp_dir/inbox.json"
inbox_status=$(request_json GET '/mobile/inbox' '' "$token" "$inbox_file")
assert_status "$inbox_status" 200 'Inbox list'
print_section 'INBOX' "$inbox_file"

conversation_id=$(php -r '$data=json_decode(file_get_contents($argv[1]), true); echo $data["data"][0]["id"] ?? "";' "$inbox_file")

if [[ -n "$conversation_id" ]]; then
  detail_file="$temp_dir/inbox-detail.json"
  detail_status=$(request_json GET "/mobile/inbox/${conversation_id}" '' "$token" "$detail_file")
  assert_status "$detail_status" 200 'Inbox detail'
  print_section 'INBOX DETAIL' "$detail_file"
fi

logout_file="$temp_dir/logout.json"
logout_status=$(request_json POST '/mobile/auth/logout' '{}' "$token" "$logout_file")
assert_status "$logout_status" 200 'Logout'
print_section 'LOGOUT' "$logout_file"

echo 'Smoke test completed successfully.'