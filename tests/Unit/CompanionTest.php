<?php

declare(strict_types=1);

use NativePhp\Simulator\Companion;

function fakeCompanionBinary(string $versionOutput): string
{
    $path = sys_get_temp_dir().'/fake-idb-companion-'.uniqid('', true);

    file_put_contents($path, "#!/bin/sh\necho '{$versionOutput}'\n");
    chmod($path, 0755);

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/fake-idb-companion-*') as $file) {
        unlink($file);
    }
});

it('reads the build date from --version', function () {
    $path = fakeCompanionBinary('{"build_date":"Sep 29 2026","build_time":"09:21:43"}');

    expect(Companion::buildDate($path))->toBe('Sep 29 2026');
});

it('has no build date for a binary that answers nothing parseable', function () {
    $path = fakeCompanionBinary('not json');

    expect(Companion::buildDate($path))->toBeNull();
});

it('has no build date for a path that does not exist', function () {
    expect(Companion::buildDate(sys_get_temp_dir().'/does-not-exist'))->toBeNull();
});

it('supports AXBRIDGE on and after the 1.6.3 build date', function () {
    $onRelease = fakeCompanionBinary('{"build_date":"Sep 29 2026","build_time":"00:00:00"}');
    $after = fakeCompanionBinary('{"build_date":"Oct 1 2026","build_time":"00:00:00"}');

    expect(Companion::supportsAxBridge($onRelease))->toBeTrue()
        ->and(Companion::supportsAxBridge($after))->toBeTrue();
});

it('does not support AXBRIDGE before the 1.6.3 build date', function () {
    $path = fakeCompanionBinary('{"build_date":"Sep 8 2026","build_time":"15:16:33"}');

    expect(Companion::supportsAxBridge($path))->toBeFalse();
});

it('cannot say whether AXBRIDGE is supported without a build date', function () {
    $path = fakeCompanionBinary('not json');

    expect(Companion::supportsAxBridge($path))->toBeNull();
});
