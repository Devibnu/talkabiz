#!/usr/bin/env bash

set -euo pipefail

DEVICE_UDID="${1:-59B9E186-1F94-4C0C-8598-D7AA7CF795C0}"
OUTPUT_PATH="${2:-/tmp/talkabiz-ios-sim-$(date +%Y%m%d-%H%M%S).png}"

if ! command -v xcrun >/dev/null 2>&1; then
  echo "xcrun command not found; Xcode command line tools are required" >&2
  exit 1
fi

xcrun simctl io "$DEVICE_UDID" screenshot "$OUTPUT_PATH" >/dev/null
echo "$OUTPUT_PATH"