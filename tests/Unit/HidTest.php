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
