# Simulator tests

A Pest suite that drives a NativePHP app on a booted Simulator or Emulator.

## Language

**Mobile suite**:
Tests registered inside `mobile(function () { ... })`.
_Avoid_: Device test, simulator block

**Screen**:
The app's current view inside a test. `screen('/lights')` opens that route.
_Avoid_: Page, visit, mobile

**Platform**:
`ios` or `android`.
_Avoid_: OS, target

**Device**:
A named Simulator or AVD. A run is one device. A suite can list several, and they run one at a time.
_Avoid_: UDID, emulator as a synonym for both platforms

**Driver**:
The platform-specific way a Screen taps, types, and reads the view.
_Avoid_: Companion, bridge
