#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="$REPO_ROOT/mobile/talkabiz_mobile"
BUILD_CACHE_DIR="$HOME/.cache/talkabiz_mobile_build"
APP_BUILD_LINK="$APP_DIR/build"
DEVICE_NAME="${1:-iPhone 16e}"
FLUTTER_ARGS=()

if ! command -v flutter >/dev/null 2>&1; then
  echo "flutter command not found in PATH" >&2
  exit 1
fi

if ! command -v xcrun >/dev/null 2>&1; then
  echo "xcrun command not found; Xcode command line tools are required" >&2
  exit 1
fi

DEVICE_UDID="$({ xcrun simctl list devices available 2>/dev/null || true; } | awk -F '[()]' -v name="$DEVICE_NAME" '$0 ~ name { print $2; exit }')"

if [[ -z "$DEVICE_UDID" ]]; then
  echo "No available simulator found for device name: $DEVICE_NAME" >&2
  exit 1
fi

mkdir -p "$BUILD_CACHE_DIR"

if [[ -e "$APP_BUILD_LINK" && ! -L "$APP_BUILD_LINK" ]]; then
  echo "Refusing to replace existing non-symlink build directory: $APP_BUILD_LINK" >&2
  echo "Move or remove it first, then rerun this script." >&2
  exit 1
fi

if [[ ! -L "$APP_BUILD_LINK" ]]; then
  ln -s "$BUILD_CACHE_DIR" "$APP_BUILD_LINK"
fi

if [[ -n "${TALKABIZ_USE_PREVIEW_DATA:-}" ]]; then
  FLUTTER_ARGS+=("--dart-define=TALKABIZ_USE_PREVIEW_DATA=${TALKABIZ_USE_PREVIEW_DATA}")
fi

if [[ -n "${TALKABIZ_INITIAL_ROUTE:-}" ]]; then
  FLUTTER_ARGS+=("--dart-define=TALKABIZ_INITIAL_ROUTE=${TALKABIZ_INITIAL_ROUTE}")
fi

open -a Simulator >/dev/null 2>&1 || true
xcrun simctl boot "$DEVICE_UDID" >/dev/null 2>&1 || true
xcrun simctl bootstatus "$DEVICE_UDID" -b

pushd "$APP_DIR" >/dev/null
flutter pub get
exec flutter run -d "$DEVICE_UDID" ${FLUTTER_ARGS[@]+"${FLUTTER_ARGS[@]}"}