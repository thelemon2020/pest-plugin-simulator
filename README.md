# Pest Simulator

Pest plugin that drives a NativePHP app on an iOS Simulator or Android Emulator. Tests registered in `mobile()` open a route with `screen()`, then tap, type, and assert against the real screen.

Pest 3, 4, and 5 load the plugin on their own. A NativePHP app also gets `php artisan nativephp:simulator`, which checks the machine before the first run.

## Install

```bash
composer require nativephp/pest-plugin-simulator --dev
```

Allow Pest's plugin manager in the app's `composer.json` if Composer has not already:

```json
{
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    }
}
```

This package registers `NativePhp\Simulator\Plugin` under `extra.pest.plugins`. On install, `pestphp/pest-plugin` writes that class to `vendor/pest-plugins.json`. Pest boots it, and the plugin fans each test inside `mobile()` out to the suite's devices. `mobile()` and `screen()` are Composer autoloaded functions, so test files can call them without a `uses()` line.

If a test says `screen() only works inside a mobile() suite` after a fresh install, regenerate the plugin list:

```bash
composer pest:dump-plugins
```

Laravel discovers `NativePhp\Simulator\Laravel\ServiceProvider` and registers the doctor command. No provider entry in `bootstrap/providers.php` is required.

## Configure

With [Pest Laravel](https://pestphp.com/docs/plugins), the booted app supplies `config/nativephp.php`:

- `deeplink_scheme` (`NATIVEPHP_DEEPLINK_SCHEME`) opens `myapp://lights`
- `deeplink_host` (`NATIVEPHP_DEEPLINK_HOST`) opens `https://example.net/lights` when no custom scheme is set
- `app_id` (`NATIVEPHP_APP_ID`) is the bundle id used to install the SQLite snapshot and launch Android

When both a scheme and a host are set, `screen()` uses the custom scheme. Pass a full URL when the test should open the https host:

```php
screen('https://example.net/lights');
```

Set the values in `tests/Pest.php` when the suite does not boot the Laravel app:

```php
<?php

use NativePhp\Simulator\Configuration;

Configuration::configure([
    'scheme' => 'myapp',
    'host' => 'example.net',
    'bundle_id' => 'com.example.app',
]);
```

## Write a test

```php
<?php

mobile(function () {
    it('turns the lights on', function () {
        screen('/lights')
            ->tap('Turn on')
            ->assertSee('The lights are on');
    });

    it('stays on the phone', function () {
        screen('/lights')->assertSee('Lights');
    })->group('ios');
})->ios(['iPhone 17 Pro']);
```

`mobile()` with no platform runs the newest iPhone and the first Android AVD, one after the other. `->ios()` and `->android()` narrow the suite. `->group('ios')` or `->group('android')` narrows one test.

## Drive the screen

```php
screen('/notes')
    ->assertNavTitle('Notes')
    ->assertTabActive('Home')
    ->type('Title', "Ada's note: 1+1")
    ->assertValue('Title', "Ada's note: 1+1")
    ->scroll('down')
    ->tap('Save');

screen('/notes/1')
    ->swipe('down')
    ->goBack()
    ->assertNavigatedTo('/notes');
```

`type()` replaces the field. `scroll('down')` reveals content further down the list. `swipe('down')` moves a finger down, which dismisses a modal. `swipe('left', 'Note')` starts that gesture on a row. `goBack()` presses Android back and swipes in from the left edge on iOS.

`assertNavTitle()` reads the navigation bar. `assertTabActive()` reads the selected bottom nav or tab. `assertNavigatedTo('/notes')` passes when that path is an accessibility id, or when the navigation title is the path's last segment. `assertEnabled()`, `assertDisabled()`, and `assertChecked()` read those states from the same tree.

The plugin reads native components. A `<webview>` is one node, so Blade and Livewire inside it are outside `tap()`, `type()`, and `assertSee()`.

iOS needs Xcode and [`idb_companion`](https://github.com/facebook/idb) (`brew install idb-companion`). Android needs `ANDROID_HOME` (or `ANDROID_SDK_ROOT`) with `adb` and `emulator`. The first `screen()` on a device runs `php artisan native:run` for that platform.

## Choose a device from the CLI

```bash
vendor/bin/pest --ios
vendor/bin/pest --android
vendor/bin/pest --ios --device="iPhone 17 Pro"
vendor/bin/pest --device=ios:"iPhone 17 Pro" --device=android:"Pixel 8"
```

`--ios` and `--android` keep that platform from the suite. `--device` runs that named device. Prefix a name with `ios:` or `android:` to pin both platforms in one command. A bare name on a single-platform run replaces the suite's device. These options are removed before PHPUnit starts, and a parallel worker inherits the same selection.

## Check the machine

```bash
php artisan nativephp:simulator
php artisan nativephp:simulator doctor
vendor/bin/pest --simulator-doctor
```

The doctor reports Xcode's `simctl`, `idb_companion`, the Android SDK, `adb`, `emulator`, the deep link scheme, the deep link host, and the app id. The command exits with a failure when the app id is missing, both link values are missing, or neither platform has its tools.

## Cleanup

A finished Pest process shuts down the Simulator, Emulator, and `idb_companion` that the run started. A device that was already booted, and a companion that was already listening, stay up.
