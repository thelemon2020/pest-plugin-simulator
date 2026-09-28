# Pest Simulator

A Pest plugin for NativePHP. Tests in `mobile()` open a screen, then tap, type, and check what is actually on the device.

Pest 4 and Pest 5 load the plugin for you. In a Laravel app, `php artisan nativephp:simulator` checks that the machine is ready.

## Install

```bash
composer require thelemon2020/pest-plugin-simulator --dev
```

If Composer has not already allowed Pest plugins, add this to the project's `composer.json`:

```json
{
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    }
}
```

`mobile()` and `screen()` are loaded by Composer, so test files can call them with no `uses()` line.

If a fresh install says `screen() only works inside a mobile() suite`, rebuild the plugin list:

```bash
composer pest:dump-plugins
```

Laravel finds `NativePhp\Simulator\Laravel\ServiceProvider` on its own. You do not add it to `bootstrap/providers.php`.

## Configure

With [Pest Laravel](https://pestphp.com/docs/plugins), the plugin reads `config/nativephp.php`:

- `deeplink_scheme` (`NATIVEPHP_DEEPLINK_SCHEME`) opens `demo://settings`
- `deeplink_host` (`NATIVEPHP_DEEPLINK_HOST`) opens `https://example.net/settings` when no custom scheme is set
- `app_id` (`NATIVEPHP_APP_ID`) is the bundle id used to install the database and launch Android

If both a scheme and a host are set, `screen()` uses the scheme. Pass a full URL when the test should open the https host:

```php
screen('https://example.net/settings');
```

`Configuration::configure()` beats an environment variable. An environment variable beats `config/nativephp.php`. `timeout` can only be set with `Configuration::configure()`. `permissions()` beats `Configuration::configure(['permissions' => ...])`. Timeout and permissions do not come from the environment.

Set the values in `tests/Pest.php` when the suite does not boot Laravel:

```php
<?php

use NativePhp\Simulator\Configuration;

Configuration::configure([
    'scheme' => 'demo',
    'host' => 'example.net',
    'bundle_id' => 'com.example.app',
]);
```

## Write a test

```php
<?php

mobile(function () {
    it('saves the form', function () {
        screen('/settings')
            ->tap('Save')
            ->assertSee('Saved');
    });

    it('shows the title on iPhone', function () {
        screen('/settings')->assertSee('Settings');
    })->group('ios');
})->ios(['iPhone 17 Pro']);
```

`mobile()` with no platform runs the newest iPhone, then the first Android emulator. `->ios()` and `->android()` limit the whole suite. `->group('ios')` or `->group('android')` limits one test.

## Before the screen opens

The first `screen()` on a device looks for a debug build. If that build is already installed, it does not compile again, and it does not start the app to check. If no debug build is installed, it runs `php artisan native:run` with `--build=debug`, then force-stops the app. Pass `--rebuild` to compile again anyway.

Each test copies the SQLite database into the app before its first `screen()` opens the route. The app is stopped for that copy. NativePHP runs migrations on launch, so opening the screen migrates the copy. A database bundled into the install does not replace it.

On iOS the file is `Library/Application Support/database/database.sqlite`. On Android it is `app_storage/persisted_data/database/database.sqlite`.

Another `screen()` in the same test keeps rows the app has written. The next test copies the database again.

The database must be a file. Set `DB_CONNECTION=sqlite`. An in-memory database cannot be copied, so the test stops.

## Drive the screen

```php
screen('/settings')
    ->assertNavTitle('Settings')
    ->assertTabActive('Home')
    ->type('Name', "Sam's name")
    ->assertValue('Name', "Sam's name")
    ->scroll('down')
    ->tap('Save');

screen('/settings/edit')
    ->swipe('down')
    ->goBack()
    ->assertNavigatedTo('/settings');
```

`type()` replaces the current text. On iOS it uses a US keyboard. A character that keyboard does not have throws. On Android, normal ASCII is typed one character at a time, because typing a whole string at once drops letters. Any other character, including a new line, is pasted. A new line does not press Enter.

`scroll('down')` moves the page so you can see what is further down. `swipe('down')` moves a finger down, which closes a sheet. `swipe('left', 'Item')` starts that swipe on a row. `goBack()` presses Back on Android, and swipes in from the left edge on iOS.

`assertNavTitle()` reads the navigation bar. `assertTabActive()` reads the selected tab. `assertNavigatedTo('/settings')` passes when that path is an accessibility id, or when the navigation title is the last part of the path (`Settings`). `assertEnabled()`, `assertDisabled()`, and `assertChecked()` read those states from the same screen.

The plugin only sees native controls. A `<webview>` is one node. Blade and Livewire inside it are outside `tap()`, `type()`, and `assertSee()`.

`tap()`, `type()`, and the assertions look for an accessibility id first, then an exact label, then a label that contains the text. The label is what the phone reads aloud: the visible text, or the `a11y-label`. An icon button, chip, tab, or nav action has no label until that prop is set. `tap('save-button')` matches an accessibility id even when the control has no visible text.

iOS needs Xcode and [`idb_companion`](https://github.com/facebook/idb) (`brew install idb-companion`). Android needs `ANDROID_HOME` (or `ANDROID_SDK_ROOT`) with `adb` and `emulator`.

## Grant permissions

The first `screen()` in a test grants privacy access after the app is installed and before that screen opens. Later screens in the same test do not grant again. The next test can ask for a different list. Permissions it drops are revoked. Permissions it adds are granted.

iOS runs `xcrun simctl privacy <udid> grant <service> <bundle-id>`. Android runs `adb shell pm grant <bundle-id> <permission>`. A permission the app did not declare is skipped.

| Service | Used by | iOS | Android |
| --- | --- | --- | --- |
| `camera` | `Camera::getPhoto()`, `Camera::recordVideo()` | `microphone`. The simulator has no camera grant. Video recording asks for the microphone. | `CAMERA`, `RECORD_AUDIO` |
| `photos` | `Camera::pickImages()` | `photos`, `media-library` | `READ_MEDIA_IMAGES`, `READ_MEDIA_VIDEO`, `READ_MEDIA_AUDIO`, `ACCESS_MEDIA_LOCATION` |
| `location` | `Geolocation` while the app is open, and `Camera` when `includeLocation` is set | `location` | `ACCESS_COARSE_LOCATION`, `ACCESS_FINE_LOCATION` |
| `notifications` | `LocalNotifications::requestPermission()` | none. The simulator has no notification grant. | `POST_NOTIFICATIONS` |
| `contacts` | reading and writing contacts | `contacts` | `READ_CONTACTS`, `WRITE_CONTACTS` |

`Biometrics::prompt()` is the system login sheet. It is not one of these grants.

The default is every row in the table. Ask for fewer before the first `screen()` in that test:

```php
permissions(['camera', 'photos']);

screen('/settings')->tap('Add photo');
```

`permissions([])` grants nothing. Use `Configuration::configure(['permissions' => ['location']])` to set one list for every test. A `permissions()` call in the test wins.

An iOS camera prompt or notification prompt cannot be granted ahead of time. When the test expects that prompt, answer it with `alert('Allow')`.

## Answer system sheets

An alert, action sheet, share sheet, or photo picker stays up until the test taps something. If the app did not open that sheet, the call fails.

```php
screen('/settings')
    ->tap('Remove')
    ->alert('Remove');

screen('/settings')
    ->tap('Share')
    ->share('Copy');

screen('/settings')
    ->tap('Share')
    ->share();

screen('/settings')
    ->tap('Add photo')
    ->pickPhoto();

screen('/settings')
    ->tap('Add photo')
    ->cancelPhoto();
```

`alert()` taps that button on the alert or action sheet. `share()` closes the share sheet. `share('Copy')` taps a named share action. `pickPhoto()` taps the first image, then Add or Done if the picker asks. `cancelPhoto()` taps Cancel.

The iOS "Open in…" dialog and Android's "Wait" button are closed automatically.

## When an assertion fails

A failed assertion writes `tree.json` and `screen.png`. It also copies `laravel.log` out of the app: `Library/Application Support/storage/logs/laravel.log` on iOS, and `app_storage/persisted_data/storage/logs/laravel.log` on Android. Android also writes `logcat.txt` for that app id. The failure message lists each file it wrote, and it lists the controls on screen. A control with no label is named by its role, and by its accessibility id when it has one.

## Choose a device from the CLI

```bash
vendor/bin/pest --ios
vendor/bin/pest --android
vendor/bin/pest --ios --device="iPhone 17 Pro"
vendor/bin/pest --device=ios:"iPhone 17 Pro" --device=android:"Pixel 8"
vendor/bin/pest --rebuild
```

`--ios` and `--android` keep that platform. A test that asks for the other platform is skipped. `--device` runs that named device. Prefix the name with `ios:` or `android:` to choose both platforms in one command. A name with no prefix, on a one-platform run, replaces the suite's device. `--rebuild` runs `native:run` even when a debug build is already installed.

These options are removed before PHPUnit starts. A parallel worker gets the same choice, on its own `idb_companion` port and its own booted device.

## Check the machine

```bash
php artisan nativephp:simulator
php artisan nativephp:simulator doctor
vendor/bin/pest --simulator-doctor
```

The doctor checks `simctl`, `idb_companion`, the Android SDK, `adb`, `emulator`, the deep link scheme, the deep link host, and the app id. It fails when the app id is missing, when both link values are missing, or when neither platform has its tools.

## Run in CI

[`.github/workflows/mobile.yml`](.github/workflows/mobile.yml) runs this package's own tests on PHP 8.3 with Pest 4, and on PHP 8.4 with Pest 5. Those tests fake the machine. They do not boot a Simulator or an Emulator.

In a project, install that platform's tools and run `vendor/bin/pest --ios` or `vendor/bin/pest --android`. A Mac can run the Android Emulator too. Each Pest worker gets its own `idb_companion` port and its own booted device. The Android Emulator starts with no window, draws in software, and cold-boots from an empty snapshot. If it does not finish, the error names the emulator log.

A test limited to a platform the suite does not run is skipped. A machine without Xcode and `idb_companion`, or without the Android SDK, skips the tests that needed that platform.

## Cleanup

When Pest finishes, it shuts down the Simulator, Emulator, and `idb_companion` that this run started. A device that was already booted stays up. An `idb_companion` already listening for this simulator is reused. One listening for a different simulator is left alone, and the plugin starts its own on the next port.
