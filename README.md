# Pest Simulator

Pest plugin that drives a NativePHP app on an iOS Simulator or Android Emulator. Tests registered in `mobile()` open a route with `screen()`, then tap, type, and assert against the real screen.

Pest 4 and 5 load the plugin on their own. A NativePHP app also gets `php artisan nativephp:simulator`, which checks the machine before the first run.

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

`Configuration::configure()` wins over the environment, and the environment wins over `config/nativephp.php`. `timeout` comes only from `Configuration::configure()`. `permissions()` wins over `Configuration::configure(['permissions' => ...])`. Neither comes from the environment.

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

## Prepare the app

The first `screen()` on a device skips the build when a debug app for this bundle is already on the booted device. That check does not start the app. When no debug app is installed, the screen runs `php artisan native:run` with `--build=debug`, which installs and launches, and the plugin then force-stops the app. Pass `--rebuild` to compile again.

The first `screen()` of each test copies the host SQLite database into the app container while the app is stopped, and only then opens the route. NativePHP runs migrations when the app starts, so that open migrates the snapshot. An install-time database does not replace it. On iOS the file is `Library/Application Support/database/database.sqlite`. On Android it is `app_storage/persisted_data/database/database.sqlite`. A later `screen()` in the same test leaves rows the app wrote. The next test copies the snapshot again before it opens the app.

The host connection has to be a file (`DB_CONNECTION=sqlite`). An in-memory database stops the test: the snapshot needs a file-backed SQLite database.

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

`type()` replaces the field. On iOS it sends US-keyboard keys, and a character outside that set throws. On Android, printable ASCII is typed one character at a time so Compose does not drop letters. Anything else, including a newline, is pasted, so a newline does not press Enter. `scroll('down')` reveals content further down the list. `swipe('down')` moves a finger down, which dismisses a modal. `swipe('left', 'Note')` starts that gesture on a row. `goBack()` presses Android back and swipes in from the left edge on iOS.

`assertNavTitle()` reads the navigation bar. `assertTabActive()` reads the selected bottom nav or tab. `assertNavigatedTo('/notes')` passes when that path is an accessibility id, or when the navigation title is the path's last segment. `assertEnabled()`, `assertDisabled()`, and `assertChecked()` read those states from the same tree.

The plugin reads native components. A `<webview>` is one node, so Blade and Livewire inside it are outside `tap()`, `type()`, and `assertSee()`.

`tap()`, `type()`, and the assertions match an accessibility id, then an exact label, then a label that contains the text. That label is the text the platform announces: the control's visible text, or its `a11y-label`. An icon button, chip, tab, or nav action announces nothing until that prop is set. `screen()->tap('save-button')` matches an accessibility id, including when the control has no visible text. NativePHP's in-process `ref` reaches this tree when the native view publishes that ref as the accessibility id. `assertAccessible()` flags a missing label on the wire tree before a device run.

iOS needs Xcode and [`idb_companion`](https://github.com/facebook/idb) (`brew install idb-companion`). Android needs `ANDROID_HOME` (or `ANDROID_SDK_ROOT`) with `adb` and `emulator`.

## Grant permissions

The first `screen()` on a booted device grants privacy access after the app is installed and before that screen opens. Later screens on the same device do not grant again.

iOS runs `xcrun simctl privacy <udid> grant <service> <bundle-id>`. Android runs `adb shell pm grant <bundle-id> <permission>`. A permission the app did not declare is skipped. These are the services the NativePHP facades prompt for:

| Service | What asks for it | iOS | Android |
| --- | --- | --- | --- |
| `camera` | `Camera::getPhoto()`, `Camera::recordVideo()` | `microphone`. `simctl` has no camera service; recording asks for the microphone | `CAMERA`, `RECORD_AUDIO` |
| `photos` | `Camera::pickImages()` | `photos`, `media-library` | `READ_MEDIA_IMAGES`, `READ_MEDIA_VIDEO`, `READ_MEDIA_AUDIO`, `ACCESS_MEDIA_LOCATION` |
| `location` | `Geolocation` while the app is in use, and `Camera` when `includeLocation` is set | `location` | `ACCESS_COARSE_LOCATION`, `ACCESS_FINE_LOCATION` |
| `notifications` | `LocalNotifications::requestPermission()` | none. `simctl` has no notifications service | `POST_NOTIFICATIONS` |
| `contacts` | contact read and write | `contacts` | `READ_CONTACTS`, `WRITE_CONTACTS` |

`Biometrics::prompt()` is the system authentication sheet, not one of these grants.

The default is the whole set. The first `screen()` in a test grants that test's set. A later `screen()` in the same test does not grant again. The next test can ask for a different set: services it drops are revoked, and services it adds are granted. Opt into a smaller set before the first `screen()` in that test:

```php
permissions(['camera', 'photos']);

screen('/profile')->tap('Take photo');
```

`permissions([])` grants nothing. Set the same list for every test with `Configuration::configure(['permissions' => ['location']])`. A `permissions()` call in the test wins.

An iOS camera prompt or notification prompt cannot be pre-granted. Answer it with `alert('Allow')` when the test expects the app to ask.

## Answer system sheets

`Dialog::alert()` and an action sheet stay up until the test taps a button. `Share::url()` and `Share::file()` open the share sheet. `Camera::pickImages()` opens the photo picker. None of these are dismissed on their own, so the call fails when the app did not ask.

```php
screen('/notes/1')
    ->tap('Delete')
    ->alert('Delete');

screen('/notes/1')
    ->tap('Share')
    ->share('Copy');

screen('/notes/1')
    ->tap('Share')
    ->share();

screen('/profile')
    ->tap('Choose')
    ->pickPhoto();

screen('/profile')
    ->tap('Choose')
    ->cancelPhoto();
```

`alert()` taps that label on the alert or action sheet. `share()` dismisses the sheet, and `share('Copy')` taps a named target. `pickPhoto()` taps the first image, then Add or Done when the picker is showing one. `cancelPhoto()` taps Cancel.

The iOS "Open in…" dialog and Android's "Wait" button are still dismissed automatically.

## When an assertion fails

A failed assertion writes `tree.json` and `screen.png`. It also copies the app's PHP log, `laravel.log`, out of the app container: `Library/Application Support/storage/logs/laravel.log` on iOS, and `app_storage/persisted_data/storage/logs/laravel.log` on Android. Android adds `logcat.txt`, a slice of logcat for that app id. The failure message lists each path that was written, and it lists the controls on screen. A control with no accessibility label is named by its role, and by its accessibility id when it has one.

## Choose a device from the CLI

```bash
vendor/bin/pest --ios
vendor/bin/pest --android
vendor/bin/pest --ios --device="iPhone 17 Pro"
vendor/bin/pest --device=ios:"iPhone 17 Pro" --device=android:"Pixel 8"
vendor/bin/pest --rebuild
```

`--ios` and `--android` keep that platform from the suite. A suite or test that specifies the other platform is skipped. `--device` runs that named device. Prefix a name with `ios:` or `android:` to pin both platforms in one command. A bare name on a single-platform run replaces the suite's device. `--rebuild` runs `native:run` even when a debug build is already installed. These options are removed before PHPUnit starts, and a parallel worker inherits the same selection on its own idb_companion port and booted device.

## Check the machine

```bash
php artisan nativephp:simulator
php artisan nativephp:simulator doctor
vendor/bin/pest --simulator-doctor
```

The doctor reports Xcode's `simctl`, `idb_companion`, the Android SDK, `adb`, `emulator`, the deep link scheme, the deep link host, and the app id. The command exits with a failure when the app id is missing, both link values are missing, or neither platform has its tools.

## Run in CI

[`.github/workflows/mobile.yml`](.github/workflows/mobile.yml) runs this package's unit tests on PHP 8.3 with Pest 4 and on PHP 8.4 with Pest 5. Those tests fake the machine. They do not boot a Simulator or Emulator.

In an app, a job installs that platform's tools and runs `vendor/bin/pest --ios` or `vendor/bin/pest --android`. macOS can host the Android Emulator as well. Each Pest worker gets its own `idb_companion` port and its own booted device. The Android Emulator starts headless, renders in software, and cold-boots from a wiped snapshot. If it does not finish, the failure names the emulator log.

A test limited to a platform the suite does not run is skipped. A machine without Xcode and `idb_companion`, or without the Android SDK, skips the tests that needed that platform.

## Cleanup

A finished Pest process shuts down the Simulator, Emulator, and `idb_companion` that the run started. A device that was already booted stays up. An `idb_companion` already listening for this simulator is reused. One listening for a different simulator stays up, and the plugin starts its own on the next port.
