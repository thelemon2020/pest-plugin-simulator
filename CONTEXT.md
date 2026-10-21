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

**Device database**:
The app's own SQLite database on the device, which the test database is copied into and the app writes to. `assertDatabaseHas()` and `deviceDatabase()` read a copy pulled off the device.
_Avoid_: App database, remote database

**App error**:
An entry at `ERROR` or above that the app writes to its `laravel.log` while a test drives it. It fails the step that was running.
_Avoid_: Crash, device error
