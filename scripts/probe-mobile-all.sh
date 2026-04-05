#!/usr/bin/env bash

set -o errexit
set -o nounset
set -o pipefail

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"

BASE_URL="${1:-https://talkabiz.ibnuapps.cloud/api}"
EMAIL="${2:-}"
PASSWORD="${3:-}"
DEVICE_NAME="${4:-Mobile All Probe}"

if [[ -z "$EMAIL" || -z "$PASSWORD" ]]; then
  echo "Usage: $0 <base_url> <email> <password> [device_name]" >&2
  echo "Example: $0 https://talkabiz.ibnuapps.cloud/api basic1@gmail.com ReviewMeta2026!" >&2
  exit 1
fi

run_probe() {
  local label="$1"
  local script_name="$2"
  local device_suffix="$3"

  printf '\n===== %s =====\n' "$label"
  "$BASE_DIR/$script_name" "$BASE_URL" "$EMAIL" "$PASSWORD" "$DEVICE_NAME - $device_suffix"
}

run_probe 'AUTH PROBE' 'probe-mobile-auth.sh' 'Auth'
run_probe 'CONTACTS PROBE' 'probe-mobile-contacts.sh' 'Contacts'
run_probe 'INBOX PROBE' 'probe-mobile-inbox.sh' 'Inbox'

printf '\nAll mobile probes completed successfully.\n'