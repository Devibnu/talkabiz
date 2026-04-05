#!/usr/bin/env bash

set -o errexit
set -o nounset
set -o pipefail

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"

BASE_URL="${1:-https://talkabiz.ibnuapps.cloud/api}"
EMAIL="${2:-}"
PASSWORD="${3:-}"
DEVICE_NAME="${4:-Mobile All Probe}"
SUMMARY_FILE="${5:-}"

if [[ -z "$EMAIL" || -z "$PASSWORD" ]]; then
  echo "Usage: $0 <base_url> <email> <password> [device_name] [summary_file]" >&2
  echo "Example: $0 https://talkabiz.ibnuapps.cloud/api basic1@gmail.com ReviewMeta2026! 'Mobile All Probe' ./mobile-probe-summary.json" >&2
  exit 1
fi

PROBE_RESULTS=()
FAIL_COUNT=0

write_summary_file() {
  [[ -z "$SUMMARY_FILE" ]] && return 0

  local json_file="$SUMMARY_FILE"
  local -a payload_args
  payload_args=("$json_file" "$BASE_URL" "$DEVICE_NAME" "$FAIL_COUNT")

  for result in "${PROBE_RESULTS[@]}"; do
    payload_args+=("$result")
  done

  php -r '
    $args = $argv;
    array_shift($args);
    $file = array_shift($args);
    $baseUrl = array_shift($args);
    $deviceName = array_shift($args);
    $failCount = (int) array_shift($args);
    $results = array_map(static function (string $entry): array {
      $parts = array_map("trim", explode("|", $entry, 2));
        return [
        "status" => $parts[0] ?? "UNKNOWN",
        "label" => $parts[1] ?? $entry,
        ];
    }, $args);
    $payload = [
        "generated_at" => gmdate("c"),
        "base_url" => $baseUrl,
        "device_name" => $deviceName,
        "failed_count" => $failCount,
        "passed" => $failCount === 0,
        "results" => $results,
    ];
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
  ' "${payload_args[@]}"
}

run_probe() {
  local label="$1"
  local script_name="$2"
  local device_suffix="$3"

  printf '\n===== %s =====\n' "$label"

  if "$BASE_DIR/$script_name" "$BASE_URL" "$EMAIL" "$PASSWORD" "$DEVICE_NAME - $device_suffix"; then
    PROBE_RESULTS+=("PASS | $label")
  else
    PROBE_RESULTS+=("FAIL | $label")
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi
}

run_probe 'AUTH PROBE' 'probe-mobile-auth.sh' 'Auth'
run_probe 'CONTACTS PROBE' 'probe-mobile-contacts.sh' 'Contacts'
run_probe 'INBOX PROBE' 'probe-mobile-inbox.sh' 'Inbox'

printf '\n===== SUMMARY =====\n'
for result in "${PROBE_RESULTS[@]}"; do
  printf '%s\n' "$result"
done

write_summary_file

if [[ "$FAIL_COUNT" -gt 0 ]]; then
  printf '\nMobile probes finished with %s failure(s).\n' "$FAIL_COUNT" >&2
  exit 1
fi

printf '\nAll mobile probes completed successfully.\n'

if [[ -n "$SUMMARY_FILE" ]]; then
  printf 'Summary JSON written to %s\n' "$SUMMARY_FILE"
fi