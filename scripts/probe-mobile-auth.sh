#!/usr/bin/env bash

source "$(dirname "$0")/mobile-api-probe-lib.sh"

mobile_api_require_credentials "$@"

validation_file="$MOBILE_API_TEMP_DIR/auth-validation.json"
validation_status=$(mobile_api_request_json POST '/mobile/auth/login' '{}' '' "$validation_file")
mobile_api_assert_status "$validation_status" 422 'Login validation'
mobile_api_print_section 'LOGIN VALIDATION' "$validation_file"

invalid_file="$MOBILE_API_TEMP_DIR/auth-invalid.json"
invalid_body=$(printf '{"email":"%s","password":"%s","device_name":"%s"}' "$EMAIL" '__invalid_password__' "$DEVICE_NAME")
invalid_status=$(mobile_api_request_json POST '/mobile/auth/login' "$invalid_body" '' "$invalid_file")
mobile_api_assert_status "$invalid_status" 401 'Invalid credential login'
mobile_api_print_section 'LOGIN INVALID' "$invalid_file"

unauth_me_file="$MOBILE_API_TEMP_DIR/auth-me-unauth.json"
unauth_me_status=$(mobile_api_request_json GET '/mobile/auth/me' '' '' "$unauth_me_file")
mobile_api_assert_status "$unauth_me_status" 401 'Unauthenticated me'
mobile_api_print_section 'ME UNAUTHENTICATED' "$unauth_me_file"

mobile_api_login

me_file="$MOBILE_API_TEMP_DIR/auth-me.json"
me_status=$(mobile_api_request_json GET '/mobile/auth/me' '' "$MOBILE_API_TOKEN" "$me_file")
mobile_api_assert_status "$me_status" 200 'Authenticated me'
mobile_api_print_section 'ME AUTHENTICATED' "$me_file"

mobile_api_logout

echo 'Auth probe completed successfully.'