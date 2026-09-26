# PHP speaks a small gRPC slice to idb_companion

iOS taps need a sidecar. `idb_companion` (`brew install idb-companion`) is that sidecar, the same role Node and Playwright play for Pest Browser. PHP calls `connect`, `accessibility_info`, and `hid` itself. The Python `fb-idb` client is not a dependency. Taps go to the center of an element's frame, because the accessibility action reports success and does not move the UI. Android uses `adb` and UiAutomator, not `idb_companion`.
