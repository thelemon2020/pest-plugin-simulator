<?php

declare(strict_types=1);

use NativePhp\Simulator\Hid;

it('asks accessibility_info for the COMPLETE format from the AXBRIDGE backend', function () {
    // Field 3 (format) = 2 (COMPLETE), field 8 (backend) = 2 (AXBRIDGE) — see idb's
    // idb.proto AccessibilityInfoRequest. AX (1) can't read a native TabView's tab bar at
    // all; this is a regression guard against silently switching back to it. AXBRIDGE needs
    // idb_companion 1.6.3+ — see Companion::supportsAxBridge() and the note in Hid.php.
    expect(Hid::accessibilityInfo())->toBe("\x18\x02\x40\x02");
});

it('asks the companion to connect a hardware keyboard', function () {
    // SettingRequest.hardwareKeyboard (1) > HardwareKeyboard.enabled (1) = true — see idb's
    // idb.proto. idb_companion 1.6.6 logs it as the HardwareKeyboard setting.
    expect(Hid::hardwareKeyboard())->toBe("\x0a\x02\x08\x01");
});

it('presses and lets go of the home button', function () {
    // HIDEvent.press (1) > HIDPress.action (1) > HIDPressAction.button (2) >
    // HIDButton.button (1) = HOME (1). The release adds HIDPress.direction (2) = UP (1).
    expect(array_map(bin2hex(...), Hid::home()))->toBe([
        '0a060a0412020801',
        '0a080a04120208011001',
    ]);
});
