<?php

declare(strict_types=1);

use NativePhp\Simulator\Hid;

it('asks accessibility_info for the COMPLETE format from the AX backend', function () {
    // Field 3 (format) = 2 (COMPLETE), field 8 (backend) = 1 (AX) — see idb's idb.proto
    // AccessibilityInfoRequest. AXBRIDGE (2) is the one that hangs; this is a regression
    // guard against silently switching back to it.
    expect(Hid::accessibilityInfo())->toBe("\x18\x02\x40\x01");
});
