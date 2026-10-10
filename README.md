# Pest Simulator

> **Early days.** This package is under active, early development, and the API and behavior
> may still shift. If you hit a bug or need a feature it doesn't cover yet, please
> [open an issue](https://github.com/thelemon2020/pest-plugin-simulator/issues) or a pull
> request — both are very welcome.

A Pest plugin for NativePHP. Every test that calls `screen()` has to sit inside `mobile()`. That is what boots the simulator or emulator. `screen()` opens a route, then you tap, type, and check the device.

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

If the test is already inside `mobile()` and a fresh install still says `screen() only works inside a mobile() suite`, rebuild the plugin list:

```bash
composer pest:dump-plugins
```

Laravel finds `NativePhp\Simulator\Laravel\ServiceProvider` on its own. You do not add it to `bootstrap/providers.php`.

The Composer package does not include a phone. Install the iOS Simulator, the Android Emulator, or both. A missing platform skips those tests. `php artisan nativephp:simulator` checks that the tools are there.

### iOS Simulator

This only runs on a Mac.

1. Install [Xcode](https://developer.apple.com/xcode/) from the App Store. Open it once and let it finish installing the iOS Simulator. `xcrun simctl` has to work.
2. Install the tool the plugin uses to tap and type. Needs **1.6.3 or newer** — an older
   build hangs reading a tab bar or anything else that needs `AXBRIDGE` (a 60s hang per
   read, with nothing to show for it); `brew upgrade idb-companion` if you already have it:

```bash
brew install idb-companion
```

With no `->ios([...])` list, tests use the newest iPhone that Xcode has installed. Download another simulator runtime from Xcode's Settings, under Platforms, when you need a different iPhone.

### Android Emulator

1. Install [Android Studio](https://developer.android.com/studio). That installs the SDK, `adb`, and the emulator.
2. In Android Studio, open **Device Manager** and create a virtual device. With no `->android([...])` list, tests use the first one in that list. If none exist, the test stops with `No Android AVD is installed.`
3. Point `ANDROID_HOME` at the SDK. Android Studio uses `~/Library/Android/sdk` on a Mac and `~/Android/Sdk` on Linux. `ANDROID_SDK_ROOT` works too.

```bash
export ANDROID_HOME="$HOME/Library/Android/sdk"
```

## Configure

`screen('/settings')` needs to know which app to open, and how to open it. Set these three values.

When the tests boot Laravel, put them in `.env`. That is enough. You do not also call `Configuration::configure()`.

```dotenv
NATIVEPHP_DEEPLINK_SCHEME=demo
NATIVEPHP_DEEPLINK_HOST=example.net
NATIVEPHP_APP_ID=com.example.app
```

`NATIVEPHP_DEEPLINK_SCHEME` is the app's own scheme. `screen('/settings')` opens `demo://settings`.

`NATIVEPHP_DEEPLINK_HOST` is the website host. It is used only when the scheme is empty. Then the same call opens `https://example.net/settings`, and the phone still opens it in the app. That is an app link, not a page in a browser.

If both are set, the scheme is used. You do not pass the https address yourself.

`NATIVEPHP_APP_ID` is the iOS bundle id and the Android application id. The plugin uses it to copy the database into the app and to launch the Android app.

When the tests do not boot Laravel, `.env` is never read. Set the same three values in `tests/Pest.php`:

```php
<?php

use NativePhp\Simulator\Configuration;

Configuration::configure([
    'scheme' => 'demo',
    'host' => 'example.net',
    'bundle_id' => 'com.example.app',
]);
```

`scheme` is `NATIVEPHP_DEEPLINK_SCHEME`. `host` is `NATIVEPHP_DEEPLINK_HOST`. `bundle_id` is `NATIVEPHP_APP_ID`.

If the same value is set in more than one place, the plugin uses `Configuration::configure()` first, then the environment variable, then `config/nativephp.php`. A booted Laravel app already fills that config from `.env`, so the `.env` file is the one to edit.

`timeout`, `permissions`, and `record_failures` are not `.env` variables. Set a timeout with `Configuration::configure(['timeout' => 15])`. Set permissions with `permissions()` inside a test, or with `Configuration::configure(['permissions' => ['location']])` for every test. `record_failures` is under [Debug a flaky test](#debug-a-flaky-test).

## Write a test

**Tests that touch the device must be wrapped in `mobile()`.** A plain `it()` does not boot one. `screen()` stops with `screen() only works inside a mobile() suite`.

`mobile()` runs each test inside it once per device, one device after another.

```php
<?php

mobile(function () {
    it('saves the form', function () {
        screen('/settings')
            ->tap('Save')
            ->assertSee('Saved');
    });
});
```

Nothing after `mobile()` means two devices: the newest iPhone, then the first Android emulator. The test above runs twice.

`->ios()` drops Android. `->android()` drops iPhone. A list of names chooses the devices. One name is one run.

```php
mobile(function () {
    it('saves the form', function () {
        screen('/settings')->tap('Save');
    });
})->ios(['iPhone 17 Pro']);
```

That test runs once, on the iPhone 17 Pro simulator.

```php
mobile(function () {
    it('saves the form', function () {
        screen('/settings')->tap('Save');
    });
})->ios(['iPhone 17 Pro', 'iPhone 17'])->android(['Pixel 8']);
```

That test runs three times: iPhone 17 Pro, then iPhone 17, then the Pixel 8 emulator.

`->ios()` or `->android()` with no list still keeps only that platform. The plugin picks the device: the newest iPhone, or the first Android emulator.

`->group('ios')` and `->group('android')` do not change the device list. They skip one test on the other platform.

```php
mobile(function () {
    it('saves the form', function () {
        screen('/settings')->tap('Save');
    });

    it('shows the iPhone title', function () {
        screen('/settings')->assertSee('Settings');
    })->group('ios');
});
```

The first test runs on iPhone and Android. The second test runs only while the current device is an iPhone.

## Before the screen opens

The first `screen()` on a device looks for a debug build. If that build is already installed, it does not compile again, and it does not start the app to check. If no debug build is installed, it runs `php artisan native:run` with `--build=debug`, then force-stops the app. Pass `--rebuild` to compile again anyway.

Each test copies the SQLite database into the app before its first `screen()` opens the route. The app is stopped for that copy. NativePHP runs migrations on launch, so opening the screen migrates the copy. A database bundled into the install does not replace it.

On iOS the file is `Library/Application Support/database/database.sqlite`. On Android it is `app_storage/persisted_data/database/database.sqlite`.

Another `screen()` in the same test keeps rows the app has written. The next test copies the database again.

The database must be a file. Set `DB_CONNECTION=sqlite`. An in-memory database cannot be copied, so the test stops.

## Drive the screen

These calls go inside a test that is wrapped in `mobile()`.

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

`type()` replaces the current text in whichever field is focused. On iOS, letters, digits, and spaces use a US keyboard. A character that keyboard does not have throws. Anything else is tapped when that key is on the glass. A key reported below the display is not tapped, because that tap lands on the home indicator and dismisses the keyboard. The simulator is booted with a hardware keyboard connected, and that keyboard types the character instead. The software keyboard does not insert Shift-2, and a paste chord does not land in the field. On Android, letters, digits, and spaces are typed one character at a time, because typing a whole string at once drops letters. `@` is tapped on the software keyboard, because `input text` sends it as Shift-2 and the software keyboard drops the letters around that event. When that key is not on screen, only `@` is pasted. Any other character that call cannot type, including a new line, is pasted. A new line does not press Enter. iOS reads a password field back as one dot per character, so `type()` can only check how many characters landed there, and `assertValue()` sees the dots until the password is shown.

`scroll('down')` moves the page so you can see what is further down. `scroll('down', 0.3)` moves a shorter way: `0.3` is 30% of the screen. `scroll('down', seconds: 0.15)` is a quicker flick. The finger stays on the glass: a scrolled page reports rows below the fold, and those coordinates are not the screen. `swipe('down')` moves a finger down, which closes a sheet. `swipe('left', 'Item')` starts that swipe on a row. `swipe('down', distance: 0.3)` and `swipe('left', 'Item', seconds: 0.15)` change how far and how fast. `press('Item')` holds a finger on that control, which opens a context menu. `press('Item', 1.5)` holds longer. `goBack()` taps the navigation Back button, including Android's `arrow_back` icon, when that control is on screen. Otherwise it presses Back on Android and swipes in from the left edge on iOS. A tab label drawn in the gesture-navigation strip is pressed on the tab cell above the label.

`tap()`, `press()`, `type()`, `clear()`, and `swipe('left', 'Item')` bring the control on screen first. They scroll down to a row below the fold, and up to a row above the screen. A card past the edge of a row that scrolls sideways, like a carousel of chips, is dragged into view inside that row. A control past the edge of the screen in anything else fails instead of being dragged, because a sideways drag on a list row opens its swipe actions. A row under the nav bar or tab bar counts as off screen. The bars' own controls do not. After 8 scrolls, or when the timeout runs out, the call fails and says the control stayed off screen.

iOS drops a touch while a sheet slides in, and the screen it reads already has the sheet where it will stop. So `tap()`, `press()`, `type()`, `clear()`, `scroll()`, `swipe()`, and `scrollTo()` wait until 0.8 seconds after a read first showed the sheet. Once that has passed, and on a screen with no sheet, they do not wait.

`scrollTo('Item')` scrolls down until that control is on screen. A long list does not draw a row until it is close, so `tap()` alone cannot find one far down. `scrollTo('Item', 'up')` scrolls up. `scrollTo('Sun', 'right')` and `scrollTo('Mon', 'left')` drag the row on screen that scrolls sideways. When more than one row does, name a control on the one to drag: `scrollTo('Sun', 'right', 'Mon')`. iOS reports which rows scroll sideways. Android does not, so on Android always name the row. After 8 scrolls, the call fails.

`assertSee('Saved')` reads the screen. `assertSee('Save', 'Name')` checks both labels on that one read, and waits until a single read contains every label. When the wait ends, the failure names the labels still missing from that read. Another `assertSee()` reads again. A `tap()` after it reads again too, and uses that coordinate.

`assertNavTitle()` reads the navigation bar. `assertTabActive()` reads the selected tab. `assertNavigatedTo('/settings')` passes when that path is an accessibility id, or when the navigation title is the last part of the path (`Settings`). `assertEnabled()`, `assertDisabled()`, `assertChecked()`, and `assertNotChecked()` read those states from the same screen.

`assertTabActive()` and `tap()` on a tab bar button both read iOS's native `TabView` through the `AXBRIDGE` backend, which is what crosses process boundaries to see the tab bar's children — the older `AX` backend reads it as a childless group, with no per-tab `selected` flag and no tab buttons to find at all. **AXBRIDGE needs idb_companion 1.6.3 or newer** (`brew upgrade idb-companion`); `php artisan nativephp:simulator doctor` reports the installed build's date and says so if it's too old. Before 1.6.3, AXBRIDGE's guest transport was one-shot with a hardcoded 30s silence deadline per read, and a full tree read routinely exceeded it — every read hung to its own 60s ceiling and came back with nothing, even though the screen was rendering correctly underneath. 1.6.3 reworked the transport to stream and lifted that deadline.

The plugin only sees native controls. A `<webview>` is one node. Blade and Livewire inside it are outside `tap()`, `press()`, `type()`, and `assertSee()`.

`tap()`, `press()`, `type()`, and the assertions look for an accessibility id first, then an exact label, then a label that contains the text. The label is what the phone reads aloud: the visible text, or the `a11y-label`. An icon button, chip, tab, or nav action has no label until that prop is set. `tap('save-button')` matches an accessibility id even when the control has no visible text. A field with no label is named by its placeholder, but only while it is empty: iOS reports the placeholder as the field's value, and typed text replaces it. A multiline field that draws its placeholder as text over itself is named by that text the same way. `type()` checks the field where it tapped, so `type('Search…', 'Talk')` works. `assertValue('Search…', 'Talk')` cannot find that field. Give it a label to assert its value.

## Screenshots and recordings

These calls go inside a test that is wrapped in `mobile()`.

`screenshot()` writes a PNG of the current screen. Pass the file path. The directory is created when it is missing. The call stays on the screen, so the test can keep going.

```php
screen('/settings')
    ->tap('Save')
    ->screenshot('build/settings.png')
    ->assertSee('Saved');
```

A failed assertion also writes its own `screen.png`, next to `tree.json`. That file is separate from a path you passed to `screenshot()`.

`record()` saves an mp4 of the device. `stopRecord()` ends that clip. Both work as their own call, and both work on the screen.

```php
it('saves the form', function () {
    record();

    screen('/settings')
        ->tap('Save')
        ->assertSee('Saved');

    stopRecord();
});
```

`record()` before `screen()` starts once the device is booted, so the video includes the app opening. `screen()->record()` starts at that moment in the chain.

```php
screen('/settings')
    ->record()
    ->tap('Save')
    ->stopRecord();
```

`stopRecord()` and `screen()->stopRecord()` end whichever recording is in progress. Either call can follow the other. After the clip stops, `record()` can start another one. `stopRecord()` throws `No recording is in progress.` when nothing is recording.

Leave the path off and the file is written under `simulator-recordings/`. The name is the test, the platform, and the device.

```text
simulator-recordings/saves-the-form-ios-iphone-17-pro.mp4
simulator-recordings/saves-the-form-android-pixel-8.mp4
```

A test that runs on an iPhone and a Pixel writes two files. If you never call `stopRecord()`, the clip ends when the test ends. A failed assertion ends it too, and the video is kept.

`record('build/signup.mp4')` and `->record('build/signup.mp4')` write that path. The directory is created when it is missing. A second device in the same test overwrites that path, so leave the path off when the test runs on more than one device.

iOS records with `xcrun simctl io recordVideo`. Android records with `adb shell screenrecord`. Android keeps at most 3 minutes. The emulator has no window. The video is still the screen.

To record every test and keep only the ones that fail, see `--record-failures` under [Debug a flaky test](#debug-a-flaky-test).

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

A failed assertion writes `tree.json`, `screen.png`, `trace.json`, and `trace.txt`. A recording that is still running is stopped and kept. It also copies `laravel.log` out of the app: `Library/Application Support/storage/logs/laravel.log` on iOS, and `app_storage/persisted_data/storage/logs/laravel.log` on Android. Android also writes `logcat.txt` for that app id. The failure message lists each file it wrote, and it lists the controls on screen. A control with no label is named by its role, and by its accessibility id when it has one.

`trace.json` has every step the test took, from its first `screen()`: the call and its label, what it matched, where it touched, how many times it read the screen, the scrolls that brought a control on screen, any wait for a sheet or for scrolling to stop, how long the step took, and how it ended. A test that opens two screens has one trace. `trace.txt` is the same steps, one line each:

```text
it saves the form with ('ios:1:iPhone 17 Pro')

   +0.000s    2.314s  ok      open [demo://settings]
   +2.314s    0.912s  ok      tap [Save]: matched Button "Save" at 200,700; 3 reads (1 failed); scrolled down
   +3.226s   15.004s  failed  assertSee [Saved]: 38 reads; Did not see [Saved].
```

The first column is when the step started, from the start of the test. The second is how long it took.

## Debug a flaky test

`dump()` prints the controls on screen, in the list a failure shows. It returns the screen, so the chain keeps going. Pest prints it once the test ends, whether it passed or failed.

```php
screen('/settings')
    ->tap('Edit')
    ->dump()
    ->tap('Save');
```

`--simulator-verbose` logs every `simctl`, `adb`, and other command, and every `idb_companion` call, with how long it took and how it ended. It logs each test and each step too. A line is written when its call ends.

```bash
vendor/bin/pest --ios --simulator-verbose
vendor/bin/pest --ios --parallel --simulator-verbose=build/simulator.log
```

The log is `simulator-logs/verbose.log`, or the path after `=`. Each run starts the file again. It is a file, not the terminal, because a parallel worker's output is not shown. Every worker adds to the same file, and each line names its worker: `[w2]`, or `[main]` outside a worker.

```text
13:30:47.110 [w1]                         test it saves the form with ('ios:1:iPhone 17 Pro') on ios iPhone 17 Pro
13:30:47.522 [w1]      0.412s  exit 0     xcrun simctl openurl 8C1F2A47-3D7E-4C0B-9E55-2F1B6A9D0C11 demo://settings
13:30:47.605 [w1]      0.083s  grpc 0     companion accessibility_info
13:30:48.010 [w1]      0.405s  grpc 0     companion hid (2 messages, 400ms apart)
13:30:48.020 [w1]      0.912s  ok         step tap [Save]: matched Button "Save" at 200,700; 1 read
13:31:18.024 [w2]     30.004s  timed out  companion accessibility_info: Companion [accessibility_info] did not answer in time
```

A command ends with its exit code, or `timed out` when it was stopped. A companion call ends with its gRPC status, `timed out`, or the curl error. A step ends `ok`, `failed` for an assertion, or `error` when the device call threw.

`--record-failures` records every test and keeps the video only when the test fails. The clip starts at the test's first `screen()` and is written where `record()` writes it. `Configuration::configure(['record_failures' => true])` does the same for every run. A `record()` inside that test keeps the clip, and writes it to the path you passed. Recording every test makes each one slower.

`--simulator-verbose` and `--record-failures` are removed before PHPUnit starts. A parallel worker gets them too.

## Choose a device from the CLI

```bash
vendor/bin/pest --ios
vendor/bin/pest --android
vendor/bin/pest --ios --parallel
vendor/bin/pest --android --parallel
vendor/bin/pest --ios --device="iPhone 17 Pro"
vendor/bin/pest --device=ios:"iPhone 17 Pro" --device=android:"Pixel 8"
vendor/bin/pest --rebuild
vendor/bin/pest --wipe
```

`--ios` and `--android` keep that platform. A test that asks for the other platform is skipped. `--device` runs that named device. Prefix the name with `ios:` or `android:` to choose both platforms in one command. A name with no prefix, on a one-platform run, replaces the suite's device. `--rebuild` runs `native:run` even when a debug build is already installed. `--wipe` erases the Simulator, deletes a worker's cloned Simulator, and cold-boots the Emulator from an empty userdata image. The run after that quick-boots again.

These options are removed before PHPUnit starts. A parallel worker gets the same choice, on its own `idb_companion` port and its own booted device. When the run lists more than one device, each device is its own parallel lane, so that worker stays on one device.

## Check the machine

```bash
php artisan nativephp:simulator
php artisan nativephp:simulator doctor
vendor/bin/pest --simulator-doctor
```

The doctor checks `simctl`, `idb_companion`, the Android SDK, `adb`, `emulator`, the deep link scheme, the deep link host, and the app id. It fails when the app id is missing, when both link values are missing, or when neither platform has its tools.

## Run in CI

[`.github/workflows/mobile.yml`](.github/workflows/mobile.yml) runs this package's own tests on PHP 8.3 with Pest 4, and on PHP 8.4 with Pest 5. Those tests fake the machine. They do not boot a Simulator or an Emulator.

In a project, install that platform's tools and run the two platforms as separate jobs. A Mac can run the Android Emulator too.

```bash
vendor/bin/pest --ios --parallel
vendor/bin/pest --android --parallel
```

Each test is one row per device. ParaTest hands a worker a whole file. A parallel run that lists more than one device starts one lane per device, and every worker in that lane stays on it. The first lane also runs the rest of the suite. Each worker gets its own `idb_companion` port and its own booted device. An iOS worker reuses the Simulator it cloned last time (`iPhone 17 pest-1`) instead of copying a new one.

```yaml
jobs:
  ios:
    runs-on: macos-latest
    steps:
      - uses: actions/checkout@v4
      - name: Run iOS tests
        run: vendor/bin/pest --ios --parallel

  android:
    runs-on: macos-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/cache@v4
        with:
          path: ~/.android/avd
          key: android-avd-${{ runner.os }}-${{ github.sha }}
          restore-keys: android-avd-${{ runner.os }}-
      - name: Run Android tests
        run: vendor/bin/pest --android --parallel
```

The Android Emulator starts with no window and draws in software. When the AVD has a Quick Boot snapshot, the emulator loads it, so a debug build installed last time is still there and `native:run` does not compile again. The first boot is still a cold boot. That boot is saved when the emulator exits, and shutdown waits for the save to finish. One parallel worker boots writable so it can save the snapshot. The others load it read-only, each on its own emulator port. The android job caches `~/.android/avd` so that snapshot is still there on the next run. The key includes the commit, so this run saves the snapshot it just wrote. The next run restores the newest saved snapshot. Leave `--wipe` off this job. `--wipe` drops the snapshot for one run. If a boot does not finish, the error names the emulator log.

A test limited to a platform the suite does not run is skipped. A machine without Xcode and `idb_companion`, or without the Android SDK, skips the tests that needed that platform.

## Cleanup

When Pest finishes, it shuts down the Simulator, Emulator, and `idb_companion` that this run started. A device that was already booted stays up. An `idb_companion` already listening for this simulator is reused. One listening for a different simulator is left alone, and the plugin starts its own on the next port.

A run stopped with Ctrl+C or `SIGTERM`, or one that dies of a fatal error, shuts them down too. Catching the signal needs PHP's `pcntl` extension.

A `simctl`, `adb`, or other tool call that does not finish in 60 seconds is stopped, and the test fails naming that command. Listing, booting, erasing, and shutting down Simulators get 10 minutes. `native:run` gets 30.
