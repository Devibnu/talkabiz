#!/usr/bin/env bash

source "$(dirname "$0")/mobile-api-probe-lib.sh"

mobile_api_require_credentials "$@"
mobile_api_login

contacts_file="$MOBILE_API_TEMP_DIR/contacts.json"
contacts_status=$(mobile_api_request_json GET '/mobile/contacts' '' "$MOBILE_API_TOKEN" "$contacts_file")
mobile_api_assert_status "$contacts_status" 200 'Contacts list'
mobile_api_print_section 'CONTACTS' "$contacts_file"

validation_file="$MOBILE_API_TEMP_DIR/contacts-validation.json"
validation_status=$(mobile_api_request_json POST '/mobile/contacts' '{}' "$MOBILE_API_TOKEN" "$validation_file")
mobile_api_assert_status "$validation_status" 422 'Contacts create validation'
mobile_api_print_section 'CONTACTS VALIDATION' "$validation_file"

contact_name="$(mobile_api_json_get "$contacts_file" 'data.0.name')"
contact_tag="$(mobile_api_json_get "$contacts_file" 'data.0.tags.0')"

if [[ -n "$contact_name" ]]; then
  encoded_name=$(php -r 'echo rawurlencode($argv[1]);' "$contact_name")
  search_file="$MOBILE_API_TEMP_DIR/contacts-search.json"
  search_status=$(mobile_api_request_json GET "/mobile/contacts?search=${encoded_name}" '' "$MOBILE_API_TOKEN" "$search_file")
  mobile_api_assert_status "$search_status" 200 'Contacts search'
  mobile_api_print_section 'CONTACTS SEARCH' "$search_file"
fi

if [[ -n "$contact_tag" ]]; then
  encoded_tag=$(php -r 'echo rawurlencode($argv[1]);' "$contact_tag")
  tag_file="$MOBILE_API_TEMP_DIR/contacts-tag.json"
  tag_status=$(mobile_api_request_json GET "/mobile/contacts?tag=${encoded_tag}" '' "$MOBILE_API_TOKEN" "$tag_file")
  mobile_api_assert_status "$tag_status" 200 'Contacts tag filter'
  mobile_api_print_section 'CONTACTS TAG FILTER' "$tag_file"
fi

cap_file="$MOBILE_API_TEMP_DIR/contacts-cap.json"
cap_status=$(mobile_api_request_json GET '/mobile/contacts?per_page=999' '' "$MOBILE_API_TOKEN" "$cap_file")
mobile_api_assert_status "$cap_status" 200 'Contacts per-page cap'
mobile_api_print_section 'CONTACTS PER PAGE CAP' "$cap_file"

mobile_api_logout

echo 'Contacts probe completed successfully.'