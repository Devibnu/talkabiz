#!/usr/bin/env bash

source "$(dirname "$0")/mobile-api-probe-lib.sh"

mobile_api_require_credentials "$@"
mobile_api_login

inbox_file="$MOBILE_API_TEMP_DIR/inbox.json"
inbox_status=$(mobile_api_request_json GET '/mobile/inbox' '' "$MOBILE_API_TOKEN" "$inbox_file")
mobile_api_assert_status "$inbox_status" 200 'Inbox list'
mobile_api_print_section 'INBOX' "$inbox_file"

missing_file="$MOBILE_API_TEMP_DIR/inbox-missing.json"
missing_status=$(mobile_api_request_json GET '/mobile/inbox/999999' '' "$MOBILE_API_TOKEN" "$missing_file")
mobile_api_assert_status "$missing_status" 404 'Inbox missing detail'
mobile_api_print_section 'INBOX DETAIL MISSING' "$missing_file"

cap_file="$MOBILE_API_TEMP_DIR/inbox-cap.json"
cap_status=$(mobile_api_request_json GET '/mobile/inbox?per_page=999' '' "$MOBILE_API_TOKEN" "$cap_file")
mobile_api_assert_status "$cap_status" 200 'Inbox per-page cap'
mobile_api_print_section 'INBOX PER PAGE CAP' "$cap_file"

conversation_id="$(mobile_api_json_get "$inbox_file" 'data.0.id')"
conversation_phone="$(mobile_api_json_get "$inbox_file" 'data.0.phone')"
conversation_status="$(mobile_api_json_get "$inbox_file" 'data.0.status')"

if [[ -n "$conversation_phone" ]]; then
  encoded_phone=$(php -r 'echo rawurlencode($argv[1]);' "$conversation_phone")
  search_file="$MOBILE_API_TEMP_DIR/inbox-search.json"
  search_status=$(mobile_api_request_json GET "/mobile/inbox?search=${encoded_phone}" '' "$MOBILE_API_TOKEN" "$search_file")
  mobile_api_assert_status "$search_status" 200 'Inbox search'
  mobile_api_print_section 'INBOX SEARCH' "$search_file"
fi

if [[ -n "$conversation_status" ]]; then
  encoded_status=$(php -r 'echo rawurlencode($argv[1]);' "$conversation_status")
  status_file="$MOBILE_API_TEMP_DIR/inbox-status.json"
  status_filter_status=$(mobile_api_request_json GET "/mobile/inbox?status=${encoded_status}&per_page=1" '' "$MOBILE_API_TOKEN" "$status_file")
  mobile_api_assert_status "$status_filter_status" 200 'Inbox status filter'
  mobile_api_print_section 'INBOX STATUS FILTER' "$status_file"
fi

if [[ -n "$conversation_id" ]]; then
  detail_file="$MOBILE_API_TEMP_DIR/inbox-detail.json"
  detail_status=$(mobile_api_request_json GET "/mobile/inbox/${conversation_id}" '' "$MOBILE_API_TOKEN" "$detail_file")
  mobile_api_assert_status "$detail_status" 200 'Inbox detail'
  mobile_api_print_section 'INBOX DETAIL' "$detail_file"

  validation_file="$MOBILE_API_TEMP_DIR/inbox-send-validation.json"
  validation_status=$(mobile_api_request_json POST "/mobile/inbox/${conversation_id}/send" '{}' "$MOBILE_API_TOKEN" "$validation_file")
  mobile_api_assert_status "$validation_status" 422 'Inbox send validation'
  mobile_api_print_section 'INBOX SEND VALIDATION' "$validation_file"
fi

mobile_api_logout

echo 'Inbox probe completed successfully.'