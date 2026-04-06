# Mobile iOS Simulator Workflow

Use this workflow when previewing the Flutter mobile app on the local iPhone simulator.

## Why this exists

This repository lives under `Documents`, and Flutter iOS simulator builds can pick up macOS extended attributes on `build/ios/.../Flutter.framework`. That breaks Flutter's packaging/codesign step for simulator debug builds.

To avoid that, the app's local `build` path should point to a cache directory outside `Documents`.

## Run the app

From the repository root:

```bash
./scripts/run-mobile-ios-sim.sh
```

To use a different simulator device:

```bash
./scripts/run-mobile-ios-sim.sh "iPhone 17 Pro"
```

What the script does:

1. Ensures `mobile/talkabiz_mobile/build` is a symlink to `~/.cache/talkabiz_mobile_build`
2. Boots the requested simulator
3. Runs `flutter pub get`
4. Starts `flutter run` against that simulator

Once running, normal Flutter hot reload and hot restart are available.

## Capture a screenshot

```bash
./scripts/capture-mobile-ios-sim.sh
```

Optional arguments:

```bash
./scripts/capture-mobile-ios-sim.sh <simulator_udid> <output_path>
```

Example:

```bash
./scripts/capture-mobile-ios-sim.sh 59B9E186-1F94-4C0C-8598-D7AA7CF795C0 /tmp/dashboard.png
```