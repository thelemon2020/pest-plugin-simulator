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
brew install facebook/fb/idb-companion
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

`timeout`, `permissions`, and `record_failures` are not `.env` variables. Set a timeout with `Configuration::configure(['timeout' => 15])`. The timeout is how long each step waits, 15 seconds by default. The first screen after the plugin builds and installs the app gets up to 60 seconds from the launch instead, because a NativePHP app unpacks its Laravel code and runs its artisan commands on its first launch before it draws anything. On CI's Android emulator that took 15.5 seconds. Set permissions with `permissions()` inside a test, or with `Configuration::configure(['permissions' => ['location']])` for every test. `record_failures` is under [Debug a flaky test](#debug-a-flaky-test).

A test that copies the database stops the app, so its first screen launches the app again. NativePHP 4.6's iOS app unpacks its Laravel code on every launch, not only the first, unless the build number reads the same on both sides of its check. Set it in `.env`, and read it as a number in `config/nativephp.php`:

```dotenv
NATIVEPHP_APP_VERSION_CODE=1
```

```php
'version_code' => (int) env('NATIVEPHP_APP_VERSION_CODE', 1),
```

Without both, each test's first step waits for the unpack: 4 seconds a launch on an M1 Pro, and 10 to 20 on a GitHub macOS runner. With both, a rebuild at the same version would keep the old code, so before it builds the app the plugin removes the app's note of what it unpacked. The launch that ends the build unpacks the new code, and the launches after it do not.

`php artisan native:run` runs in the directory the tests run from, and the `idb_companion` and emulator logs are written there. Set another directory with `Configuration::configure(['app_directory' => '/path/to/app'])`.

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

The first `screen()` on a device looks for a debug build. If that build is already installed, it does not compile again, and it does not start the app to check. On Android that is an app marked debuggable. On iOS it is a Simulator build of the app, which NativePHP always builds in its Debug configuration. If no debug build is installed, it runs `php artisan native:run` with `--build=debug`, then force-stops the app. Pass `--rebuild` to compile again anyway.

On Android, when the emulator has to boot and the app will be built whatever it holds, with `--rebuild` or after `--wipe`, the build starts as soon as the emulator does. Gradle runs while the emulator boots, and the build no longer waits for it. `native:run` needs the device only to install the app at the end. When Gradle finishes first, that install fails, and the plugin installs the APK it built once the emulator is up. On CI, where every emulator is new and has nothing installed, pass `--rebuild` to get this. Without either flag, only the booted emulator can say whether the app is installed already, so the build waits for the boot.

Each test copies the SQLite database into the app before its first `screen()` opens the route. The app is stopped for that copy. NativePHP runs migrations on launch, so opening the screen migrates the copy. A database bundled into the install does not replace it.

On iOS the file is `Library/Application Support/database/database.sqlite`. On Android it is `app_storage/persisted_data/database/database.sqlite`.

Another `screen()` in the same test keeps rows the app has written. The next test copies the database again.

A test that only reads can skip the copy. Call `keepDatabase()` before its first `screen()`. The app keeps the database it has, and it is not stopped, so the screen opens without a cold launch. The next test copies again.

```php
it('shows the settings title', function () {
    keepDatabase();

    screen('/settings')->assertNavTitle('Settings');
});
```

`Configuration::configure(['copy_database' => false])` skips the copy in every test.

The database must be a file. Set `DB_CONNECTION=sqlite`. An in-memory database cannot be copied, so the test stops.

A test that fails with a photo picker, a permission dialog, an alert, or a share sheet open would leave it in front of the next test. Each test's first `screen()` closes it first:

- On Android, the photo picker, the documents picker, the permission dialog, and the share sheet are their own apps, and stopping your app does not close them. The one in front is force-stopped. Anything else left on top of your app's screens gets Back.
- On iOS, stopping the app for the database copy closes what it presented. When the test keeps the app running, with `keepDatabase()` or with `copy_database` off, an alert, a share sheet, or the photo picker in front stops the app, and the screen opens on a cold launch.

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

`type()` replaces the current text in whichever field is focused. On iOS, letters, digits, and spaces use a US keyboard. A character that keyboard does not have throws. Anything else is tapped when that key is on the glass. A key reported below the display is not tapped, because that tap lands on the home indicator and dismisses the keyboard. Otherwise the hardware keyboard the plugin connects (below) types the character. The software keyboard does not insert Shift-2, and a paste chord does not land in the field. On Android, the plugin's screen reader (below) presses the keys for letters, digits, spaces, and the rest of ASCII, one character at a time. NativePHP sends each change in a field to PHP and puts back what PHP renders, and a render that comes back after the next key replaces that key. So each key waits until the field shows the keys before it, and the reader waits longer between keys while the app is slow to answer. A password field reads back as dots, so there it counts them. When a key is lost anyway, or a field does not show what was typed, the reader sets the field to the whole text in one change. `clear()` empties the focused field in one change. Any other character, including a new line, is pasted: the reader puts it on the emulator's clipboard itself and presses Paste, and waits for the field to show it as it does for a key. A new line does not press Enter. Pasting replaces what was on the emulator's clipboard. When the reader is not running, or cannot set the clipboard, `adb shell input text` types one character at a time with a pause between them, and `cmd clipboard` pastes the rest. The android-35 and android-36 images have no `cmd clipboard`, so there such a character fails the step and names it. `adb shell input text` sends `@` as Shift-2, and the software keyboard drops the letters around that event, so `@` is tapped on the software keyboard instead. When that key is not on screen, only `@` is pasted, and an emulator with no `cmd clipboard` gets the At key. On Android, `type()` closes the software keyboard when it finishes, and `clear()` closes it before the screen is read again. Android's covers the lower part of the screen, where controls drop out of the tree. The field keeps its focus. iOS and Android read a password field back as one dot per character, so `type()` can only check how many characters landed there, and `assertValue()` sees the dots until the password is shown.

On iOS, keys sent through idb_companion count as a hardware keyboard's, and iOS corrects them: an iPhone 17 Pro Simulator (iOS 26.5) typed "teh quick brown fox. its done" as "The quick brown fox. It’sdone". So before the app starts, the plugin turns off auto-correction, auto-capitalization, spell checking, predictions, inline completions, the "." shortcut, and smart quotes and dashes, for the software keyboard and for a hardware keyboard (Settings > General > Keyboard). It does that once per Simulator per run, with `simctl spawn ... defaults write com.apple.keyboard.preferences`, and writes only the switches that are on. They stay off on that Simulator after the run. A Simulator booted without Simulator.app has no hardware keyboard connected, so iOS shows the software keyboard over the lower part of the screen whenever a field has focus, and a tap meant for a button under it types a letter instead. The plugin connects a hardware keyboard through idb_companion's `setting` call each time it connects to the companion, as Simulator.app's Connect Hardware Keyboard does, and iOS parks the software keyboard below the display. When a key of the software keyboard is still on the display after `type()`, the plugin connects it again and waits up to 2 seconds for the keyboard to move off. The field keeps its focus and its text.

`scroll('down')` moves the page so you can see what is further down. `scroll('down', 0.3)` moves a shorter way: `0.3` is 30% of the screen. `scroll('down', seconds: 0.15)` is a quicker flick. The finger stays on the glass: a scrolled page reports rows below the fold, and those coordinates are not the screen. `swipe('down')` moves a finger down, which closes a sheet. `swipe('left', 'Item')` starts that swipe on a row. `swipe('down', distance: 0.3)` and `swipe('left', 'Item', seconds: 0.15)` change how far and how fast. `press('Item')` holds a finger on that control, which opens a context menu. `press('Item', 1.5)` holds longer. `goBack()` taps the navigation Back button, including Android's `arrow_back` icon, when that control is on screen. Otherwise it presses Back on Android and swipes in from the left edge on iOS. A tab label drawn in the gesture-navigation strip is pressed on the tab cell above the label.

`tap()`, `press()`, `type()`, `clear()`, and `swipe('left', 'Item')` bring the control on screen first. They scroll down to a row below the fold, and up to a row above the screen. A card past the edge of a row that scrolls sideways, like a carousel of chips, is dragged into view inside that row. A control past the edge of the screen in anything else fails instead of being dragged, because a sideways drag on a list row opens its swipe actions. A row under the nav bar or tab bar counts as off screen. The bars' own controls do not. On an iPhone with no home button, a row in the bottom 34 points, under the home indicator, counts as off screen too: a scroll view draws there, but a tap there does not reach the app. After 8 scrolls, or when the timeout runs out, the call fails and says the control stayed off screen. Android leaves a control that is entirely off screen out of the tree, so there `tap()` cannot find it at all. Use `scrollTo()` first.

iOS drops a touch while a sheet slides in, and the screen it reads already has the sheet where it will stop. So `tap()`, `press()`, `type()`, `clear()`, `scroll()`, `swipe()`, `scrollTo()`, `goBack()`, `alert()`, `share()`, `pickPhoto()`, and `cancelPhoto()` wait until 0.8 seconds after a read first showed the sheet. Once that has passed, and on a screen with no sheet, they do not wait.

`scrollTo('Item')` scrolls down until that control is on screen. A long list does not draw a row until it is close, so `tap()` alone cannot find one far down. `scrollTo('Item', 'up')` scrolls up. `scrollTo('Sun', 'right')` and `scrollTo('Mon', 'left')` drag the row on screen that scrolls sideways. When more than one row does, name a control on the one to drag: `scrollTo('Sun', 'right', 'Mon')`. iOS and Android both report which rows scroll sideways. On Android that comes from the plugin's screen reader (below). When reads fall back to `uiautomator dump`, only a platform `HorizontalScrollView` counts, so name the row. After 8 scrolls, the call fails.

`assertSee('Saved')` reads the screen. `assertSee('Save', 'Name')` checks both labels on that one read, and waits until a single read contains every label. When the wait ends, the failure names the labels still missing from that read. Another `assertSee()` reads again. A `tap()` after it reads again too, and uses that coordinate.

`assertNavTitle()` reads the navigation bar. `assertTabActive()` reads the selected tab. `assertNavigatedTo('/settings')` passes when that path is an accessibility id, or when the navigation title is the last part of the path (`Settings`). `assertEnabled()`, `assertDisabled()`, `assertChecked()`, and `assertNotChecked()` read those states from the same screen.

`assertTabActive()` and `tap()` on a tab bar button both read iOS's native `TabView` through the `AXBRIDGE` backend, which is what crosses process boundaries to see the tab bar's children — the older `AX` backend reads it as a childless group, with no per-tab `selected` flag and no tab buttons to find at all. **AXBRIDGE needs idb_companion 1.6.3 or newer** (`brew upgrade idb-companion`); `php artisan nativephp:simulator doctor` reports the installed build's date and says so if it's too old. Before 1.6.3, AXBRIDGE's guest transport was one-shot with a hardcoded 30s silence deadline per read, and a full tree read routinely exceeded it — every read hung to its own 60s ceiling and came back with nothing, even though the screen was rendering correctly underneath. 1.6.3 reworked the transport to stream and lifted that deadline.

On Android, the plugin reads the screen with a small reader it copies to the emulator: `android/reader.jar`, built from `android/src` by `android/build`. The jar is committed and runs on the emulator, so running tests needs no Java. Rebuilding it after a change to `android/src` needs a JDK 17 or newer, and the pre-commit hook in `.githooks` does that rebuild (`composer install` turns the hooks on). CI builds the jar again and fails a change whose committed jar is not what `android/src` builds. d8 writes its version into the jar, so CI builds with the build-tools version pinned in `.github/workflows/mobile.yml`; rebuilding with newer build-tools means updating that pin. `uiautomator dump` starts a new process for every read and then waits a full second for the screen to sit still, so every read takes about two seconds. The reader starts once and stays running. It waits for the same second of stillness, so a read right after a tap or a scroll still lets the screen settle. A read of a screen that has already been still for that second takes a few hundredths of a second. The reader writes the same XML, plus which way each scrollable row scrolls. It types too, so `type()` starts no `adb shell input` per character and `clear()` sends no Deletes: typing 20 characters into a CollectShine field takes about 3.3 seconds instead of 5.4, most of it the reads that wait for the field to settle. When the reader cannot start or stops answering, that read uses `uiautomator dump`, and typing uses `adb shell input`. After three failures in a row, the run keeps using them.

The plugin only sees native controls. A `<webview>` is one node. Blade and Livewire inside it are outside `tap()`, `press()`, `type()`, and `assertSee()`.

`tap()`, `press()`, `type()`, and the assertions look for an accessibility id first, then an exact label, then a label that contains the text. The label is what the phone reads aloud: the visible text, or the `a11y-label`. An icon button, chip, tab, or nav action has no label until that prop is set. `tap('save-button')` matches an accessibility id even when the control has no visible text. A field with no label is named by its placeholder, but only while it is empty: iOS reports the placeholder as the field's value, and typed text replaces it. A multiline field that draws its placeholder as text over itself is named by that text the same way. An icon in the field, like a `leadingIcon`, is not its name. `type()` checks the field where it tapped, so `type('Search…', 'Talk')` works. `assertValue('Search…', 'Talk')` cannot find that field. Give it a label to assert its value.

When a label matches more than one button, the call fails and names the label. Two calls choose between them.

`within()` looks inside one control: an alert, a sheet, or a row, named by its label or accessibility id. The closure gets the screen narrowed to the controls whose center is inside that control. The control is found again on every read, so it can scroll. The screen `within()` returns still sees everything.

```php
screen('/orders')
    ->within('order-42', fn ($order) => $order->tap('Pay')->assertSee('Paid'))
    ->assertSee('1 order paid');
```

`nth()` picks one match for the next call, counting from 1 from the top of the screen, then left to right. It works with any call that looks up one control, including the assertions that read a control's state.

```php
screen('/basket')
    ->nth(2)->tap('Remove')
    ->nth(1)->assertChecked('Gift wrap');
```

`within()` needs a container the platform shows as one element: an alert, a sheet, a list row, or a view an app gives its own accessibility container. NativePHP does not do that for a `<native:column>` yet, even with an `a11y-label`: its children are read on their own, and the label goes nowhere. On those screens, `nth()` picks between the repeated controls instead.

`assertAccessible()` fails when a button, field, switch, or select on the screen has no label, and lists each one with its accessibility id and where it is. An id does not count, because a screen reader does not say it. On iOS, an icon button is named by its SF Symbol, which is what VoiceOver says. On Android, text or an `a11y-label` inside the control's frame names it, as TalkBack reads it: a checkbox is named by its text. Another control inside it does not name it. An image with no label is left out, since it is often decoration, and so are the system's photo picker and share sheet. It waits until the timeout for a label the app sets late. Inside `within()`, it checks only that control.

```php
screen('/settings')->assertAccessible();
```

## Fill in controls

These calls read the control's state first and wait until it reads what they asked for. They are named after the calls in NativePHP's own test harness, so a test can move from `Native::test()` to a device with few changes.

```php
screen('/settings')
    ->toggle('Notifications', true)
    ->check('Subscribe')
    ->select('Size', 'Large')
    ->selectRadio('Plan', 'Pro')
    ->slide('Volume', 75, min: 0, max: 100)
    ->changeTab('Activity')
    ->submit('Search…', 'Talk Talk');
```

`toggle('Notifications', true)` turns a switch on, and taps it only while it is off. `toggle('Notifications')` flips it. `check('Subscribe')` and `uncheck('Subscribe')` do the same for a checkbox. When the control does not change, the call fails: `[Notifications] stayed off.`

`select('Size', 'Large')` taps the picker, then taps the option that appeared when it opened. The picker's own label for the current choice is not an option, so `select('Size', 'Medium')` picks Medium from the menu even when Medium is showing already.

`selectRadio('Plan', 'Pro')` taps the option under the group's label, not one with the same name in a group further down, and waits until it reads as selected.

`slide('Volume', 75)` drags the slider's thumb to that value. It works out where the thumb is from the value and the slider's range, then aims each drag by how far the last one moved the value, up to five drags. Android reports the range. iOS does not, so pass the `min` and `max` the `<native:slider>` has.

`changeTab('Activity')` taps a tab in a `<native:tab-row>` or tab bar, and waits until it is the active one.

`submit('Search…', 'Talk Talk')` types into the field, then presses the keyboard's return key. Leave the text off to press return in a field that has its text already. On Android, it performs the field's IME action. NativePHP 4.6 runs a field's `@submit` only from the keyboard's own Done key, which Gboard does not show to a test, so `submit()` does not submit a NativePHP field on Android yet.

`dismissSheet()` closes the sheet that is up. On iOS it drags the sheet down by its grabber, and waits until the sheet has gone. Android presses Back, which closes a bottom sheet. When no sheet is open, it presses Back on either platform, which goes back a screen.

`longPress()`, `input()`, and `pressBack()` are `press()`, `type()`, and `goBack()` under the harness's names.

## Check the app's database

The app writes to its own database, the copy on the device. `assertDatabaseHas()`, `assertDatabaseMissing()`, `assertDatabaseCount()`, `assertDatabaseEmpty()`, `assertModelExists()`, `assertModelMissing()`, `assertSoftDeleted()`, and `assertNotSoftDeleted()` check that copy. They take the same arguments as Laravel's.

```php
it('pays an order', function () {
    $order = Order::factory()->create(['total' => 4200]);

    screen('/orders')
        ->tap('Pay')
        ->assertSee('Paid')
        ->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid'])
        ->assertDatabaseMissing(Order::class, ['status' => 'unpaid'])
        ->assertDatabaseCount('orders', 1);
});
```

The rows the test creates on the host go into the app with the database copy. A test that uses `DatabaseTransactions` or `RefreshDatabase` still copies them: the plugin reads them through the test's own connection.

Laravel's own `$this->assertDatabaseHas()` checks the host's database, not the app's. Call these on the screen instead.

Each one copies the app's database off the device: `Library/Application Support/database/database.sqlite` on iOS, and `app_storage/persisted_data/database/database.sqlite` on Android. On the Android emulator that takes about 0.04 seconds. The write-ahead log comes with it. A copy read while the app was writing can be torn, so it is checked with `PRAGMA quick_check` and copied again. In SQLite's default rollback-journal mode, a transaction writes into the database file itself, and every page of a half-written copy still looks sound. So the app's `database.sqlite-journal` is looked at before and after each copy, and a copy taken while it held a transaction is taken again.

They wait like `assertSee()`. `assertDatabaseHas()` looks again until the row is there or the timeout runs out. Each look first reads a digest of the database files' sizes and headers, which SQLite changes on every commit, in one call; the database is copied again only when that changed. On Android the copy goes straight to a file, so a large database is never held in PHP's memory. `assertDatabaseMissing()` waits until no row matches. `assertDatabaseCount()` and `assertDatabaseEmpty()` wait for the count. When the app shows the `nativephp-pending` marker (see [Wait for the app, not the clock](#wait-for-the-app-not-the-clock)), each copy waits until PHP has taken every event it was sent.

Without that marker, nothing says a tap's write has landed. `assertDatabaseMissing()` and `assertDatabaseCount()` can pass on a copy taken before it did, as `assertDontSee()` can. Put them after a step that waited for the app, like `assertSee()`.

A table can be named, or given as a model or its class. As in Laravel, a model instance is that model's row: `assertDatabaseHas($order, ['status' => 'paid'])` checks the order with `$order`'s key, not any order. A list of models, or of tables, is checked one by one, and a list of rows in `$data`, such as `[['id' => 1], ['id' => 2]]`, checks each row. `assertDatabaseCount(['orders' => 3, 'items' => 9])` checks each count. `assertSoftDeleted()` and `assertNotSoftDeleted()` read a soft-deleting model's own deleted-at column, or the `$deletedAtColumn` given for a table. A value can be a string, a number, a bool, null, an enum, or a date. Null matches a null column. The device has one database, so the `$connection` argument is not used.

A JSON column is read by its path, as Laravel reads it: `['meta->gift' => true]`, or `['meta->items[0]->sku' => 'A1']`. A condition with an operator goes under a numeric key, as Laravel's `where()` takes it: `[['total', '>', 100]]`. The operators are `=`, `<`, `>`, `<=`, `>=`, `<>`, `!=`, `like`, and `not like`. `castAsJson()` and `DB::raw()` are SQL for the test's database, so they are not taken. Compare a JSON value by its path instead.

A column the table does not have, a value that cannot be compared, an operator that is not on that list, or a query SQLite rejects, such as a JSON path on a column that is not JSON, fails the step at once, and says so. It does not wait out the timeout. A key with a double quote in it cannot be written as a SQLite JSON path.

When the row is not there, the failure says what the table holds, as Laravel's does:

```text
Did not find a row in the device's [orders] table matching {
    "id": 2,
    "status": "paid"
}.

Found similar results: [
    {
        "id": 2,
        "status": "unpaid"
    }
]
```

It saves the copy it checked as `device.sqlite`, next to `screen.png`.

`deviceDatabase()` returns a `PDO` for the same copy, with writes turned off, for anything the assertions do not cover:

```php
screen('/orders')->tap('Pay')->assertSee('Paid');

expect(deviceDatabase()->query('SELECT paid_at FROM orders')->fetchColumn())->not->toBeNull();
```

The copy is kept until the next step on the screen, or the next `screen()`. Call it after a step that waited for the app, like `assertSee()`, so what the app wrote has landed. Before the test's first `screen()`, the device still has the last test's database, so `deviceDatabase()` stops and says so.

With `keepDatabase()`, or with `copy_database` off, these still work. They check whatever the app's database holds, including rows from earlier tests.

## Fake what the app talks to

The app's code runs on the device, not in the test. Fake it the way a Laravel test does, with Laravel's own API. The plugin sends the fakes to the app, and the app runs them.

```php
it('pays at the express rate', function () {
    Http::fake([
        'api.shipping.test/*' => Http::response(['express' => 1200]),
        'api.stripe.test/*' => Http::response(['status' => 'succeeded']),
    ]);
    Http::preventStrayRequests();
    $this->travelTo('2026-12-24 09:00');
    config(['features.express' => true]);

    screen('/checkout')
        ->assertSee('Express: $12.00', 'Arrives Monday, December 28')
        ->tap('Pay')
        ->assertSee('Paid');

    Http::assertSent(fn ($request) => $request['amount'] === 4200);
});
```

These reach the app:

- `Http::fake()`, `Http::fakeSequence()`, `Http::response()`, `Http::preventStrayRequests()`, and `Http::allowStrayRequests()`.
- `Mail::fake()`, `Notification::fake()`, `Event::fake()` and `Event::fakeExcept()`, `Queue::fake()` and `Queue::fakeExcept()`, and `Bus::fake()`, with the lists of what to fake or let through.
- `Storage::fake()` and `Storage::persistentFake()`, for each disk the test fakes, with the config passed as the second argument.
- The time from `$this->travelTo()`, `$this->freezeTime()`, or `Carbon::setTestNow()`. `$this->travelBack()` lets the app's clock go again.
- `config()` values the test changed, and keys it removed. Values set in `beforeEach()` are part of the starting config the plugin compares against, so set them in the test. Only the keys the test changed are sent. Keys under `database` and `logging` are never sent: they describe the test process, and the app has its own database, copied in, and its own log, read back. A suite names more of its own with `Configuration::configure(['host_config' => ['filesystems.disks.local.root', 'services']])`, each with every key under it. A value has to be a string, a number, a bool, null, or an array of them: an enum or another object stops the test and names the key.

They need the tests to boot Laravel. The plugin's service provider gives the tests an Http client whose fakes reach the app. Outside `mobile()`, it is Laravel's own client, and nothing changes.

Before each step, the plugin writes what the test has faked to a file and copies it into the app, when it has changed since the last copy. The file is `storage/framework/pest-simulator/fakes` in the app's storage. Fakes set before the first `screen()` go in while the app is stopped for the database copy, so the app has them when it starts. A fake set later reaches the app before the next step acts.

The app runs Laravel's own fakes. A debug build of the app has this package in its vendor directory, and Laravel boots its service provider there too. When the app opens a screen, and before it handles each tap, it reads the file when it has changed, and calls `Http::fake()` with what the test passed it, `Mail::fake()` and the other fakes, `Carbon::setTestNow()`, and `config()`. Its `Event::fake()` always lets NativePHP's own `Native\` events through, since its screens listen for them. After each render, it writes what its fakes recorded to `storage/framework/pest-simulator/recorded`. With no file, it does nothing but look for one. It does nothing while it only boots, as it does for its own artisan commands: a fake config value would end up in the config cache NativePHP writes on the first launch.

The app only reads the file inside NativePHP, with `APP_DEBUG=true`, and outside `APP_ENV=production`. A release build has no dev dependencies, so the package is not in it at all. A build that has it anyway, such as one for internal testing with `APP_ENV=staging` and `APP_DEBUG=false`, ignores the file. Only the plugin's own classes are unserialized from it.

A fake the test adds after the app has its others is added to the app's client, as `Http::fake()` adds to the test's, so a sequence the app has answered from goes on from where it was. As in Laravel, that `Http::fake()` also clears what the app had recorded, so `assertSent()` sees only the requests after it. `preventStrayRequests()` alone keeps the record. A fake that changed, such as a sequence the test pushed onto after the first `screen()`, starts the app's client over. `Http::assertSequencesAreEmpty()` checks the app's copies of the sequences, which are the ones the app answers from.

`Http::assertSent()`, `Mail::assertSent()`, and the other asserts are Laravel's own. At the first step after `Mail::fake()`, the plugin swaps the test's fake for one that reads the app's record before each assert. The asserts that look for something, such as `assertSent()`, `assertSentCount()`, `assertDispatched()`, and `assertPushed()`, read it again until they pass or the timeout runs out, as `assertSee()` waits. The ones that look for nothing, such as `assertNotSent()`, `assertNothingSent()`, and `assertNotDispatched()`, check once, after the app has taken every event it was sent when it shows the `nativephp-pending` marker. Without the marker, they check what the app has recorded so far. `Mail::sent()`, `Event::dispatched()`, `Bus::hasDispatched()`, `Http::recorded()`, and the fakes' other readers read the record too. A test that keeps the fake `Mail::fake()` returns, as `$mail = Mail::fake()`, can assert through it: it holds the same record. It only sees what the facade last read, though, and does not wait for the app, so assert through the facade after a tap.

```php
it('mails a receipt', function () {
    Mail::fake();
    Event::fake([OrderPaid::class]);

    screen('/orders')->tap('Pay')->assertSee('Paid');

    Mail::assertSent(Receipt::class, fn ($mail) => $mail->order->status === 'paid');
    Event::assertDispatched(OrderPaid::class);
});
```

`Bus::assertBatched()`, `Bus::assertBatchCount()`, `Bus::assertNothingBatched()`, and `Bus::batched()` see the batches the app dispatched with `Bus::batch()`, beside the test's own.

A disk the test fakes with `Storage::fake('photos')` is faked in the app too, so the app writes to an empty disk of its own, in `storage/framework/testing/disks/photos`. After each render, the app lists that disk's files, with a hash of each, in its record. Before an assert or a read through `Storage::disk('photos')`, the plugin copies into the test's fake disk the files the app wrote or changed since the last look, and takes away the ones it deleted. A file is copied again only when it changed. `assertExists()` and `assertCount()` wait for the app, as `assertSent()` does. `assertMissing()`, `assertDirectoryEmpty()`, and `assertEmpty()` check once, after the app is idle. `get()`, `exists()`, `files()`, `allFiles()`, `directories()`, `size()`, and the disk's other readers read the app's files first. Files the test wrote to the disk itself stay beside them.

```php
it('saves the avatar', function () {
    Storage::fake('photos');

    screen('/profile')->tap('Choose photo')->tap('Save');

    Storage::disk('photos')->assertExists('avatars/1.jpg');
});
```

Assert through `Storage::disk()`. The disk `Storage::fake()` returns, kept as `$disk = Storage::fake('photos')`, has the files the last read copied, but does not read the app's itself.

As in Laravel, a fake disk keeps none of the real disk's config but `throw`. On NativePHP's `mobile_public` disk, that means `url()` no longer points at the files the web view serves, so a screen that shows a file it saved there shows nothing while the disk is faked. Pass the config the screen needs as the second argument, as in `Storage::fake('mobile_public', ['url' => '...'])`, or assert on the file rather than on the screen.

A mailable, event, job, or notification that uses `SerializesModels` brings its models back by id. They are loaded from the device database (see [Check the app's database](#check-the-apps-database)), so `$mail->order` is the order the app paid, not the test's copy of it. The relations it had loaded come with it. The model belongs to the test's default connection, so `$mail->order->is($order)` matches the test's own copy, and `fresh()` or a relation loaded later, inside the assert's closure, reads the test's database. The device has one database, so a model the app saved on a second connection is loaded from that one too.

Each entry is read on its own, from a fresh copy of the device database. One whose model the app has since deleted stays in the record as an instance of its class with nothing set: `Mail::assertSent(Receipt::class)`, the counts, and `assertNothingSent()` still see it, and only a closure that reads its models cannot.

`Mail::fake()` again part way through a test starts the record again, as it does in Laravel: what the app sent before it is gone. Mail the test sends itself, before or after the first `screen()`, is in the record beside the app's.

A mailable, event, or job that does not serialize, such as one holding a closure, is left out of the record. So are Laravel's own events that hold a request or a connection.

A request the app makes that `preventStrayRequests()` does not allow throws in the app. Unless the app catches it, NativePHP logs it, and the test fails with it (see [When the app throws](#when-the-app-throws)).

When the test ends, the plugin deletes both files, and the app puts its real Http client, mailer, dispatchers, clock, and config back before its next tap. A run stopped part way, with Ctrl-C, cannot delete them, so each test's first `screen()` deletes any it finds.

Some things do not cross over:

- A batch's `then()`, `catch()`, and `finally()` closures stay in the app. `Bus::assertBatched()` sees the batch, its name, its jobs, and its other options, but not those callbacks.
- Files the test writes to a fake disk stay in the test. The app starts with its fake disk empty.
- Jobs that NativePHP's background queue worker runs do not get the fakes. The worker is a PHP runtime of its own, on its own thread, and it runs `queue:work` without opening a screen. A job the test did not fake with `Queue::fake()` or `Bus::fake()` sends real mail, makes real requests, and writes to the real disks, and its asserts do not see it. Fake the job and assert it was pushed, or test what it does in a test of its own.
- A closure passed to a fake runs in the app. It cannot use `$this`, a class that only the tests have, or anything it captured that does not serialize. Pass what it needs with `use ()`.
- `$this->mock()`, `$this->instance()`, and `swap()` of your own classes stay in the test.
- The time moves only for PHP. The device's own clock, and the status bar, keep the real time.

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

`assertScreenshotMatches()` compares the screen with a baseline saved by an earlier run. The first run saves the baseline and passes. Commit the baseline. On CI, where the `CI` environment variable is `true`, a missing baseline fails instead, so a baseline is never made up on a machine nobody looks at.

```php
screen('/settings')
    ->assertSee('Settings')
    ->assertScreenshotMatches();
```

The baseline is `tests/.pest/simulator-screenshots/` plus the test, the platform, and the device: `shows-the-settings-ios-iphone-17-pro.png`. Each device has its own. Pass a name for a second baseline in the same test: `assertScreenshotMatches('empty')`.

Before the screenshot, the status bar is fixed at 9:41 with full signal and battery, as `fixStatusBar()` does, so the clock is not a change. The screen is compared at half size, and a color less than 24 out of 255 away in every channel counts as the same, which absorbs antialiasing. Up to 0.1% of the pixels can differ. `assertScreenshotMatches(threshold: 0.01)` allows 1%. A screen still moving gets until the timeout to match.

A mismatch fails with how much changed, and writes `screenshot-diff.png`, the new screen with the changed pixels in red, next to `screen.png` and `tree.json`. `vendor/bin/pest --update-screenshots` saves every baseline again. It needs PHP's `gd` extension.

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

## Change the device

These calls go inside a test that is wrapped in `mobile()`.

```php
appearance('dark');
textSize('large');
location(51.5072, -0.1276);
rotate('landscape');
fixStatusBar();

screen('/settings')->screenshot('build/settings-dark.png');
```

Called before `screen()`, they wait for it. They change the device after the app's database is copied and before the route opens. Called after `screen()`, they change the device at once. The screen has the same calls, in the middle of a chain:

```php
screen('/settings')
    ->appearance('dark')
    ->textSize('largest')
    ->location(48.8566, 2.3522)
    ->rotate('landscape')
    ->fixStatusBar()
    ->screenshot('build/settings-large.png');
```

A setting lasts until the test ends. The next test starts from the device's own setting. As with permissions, the plugin only changes what differs: a setting the next test asks for again is left alone, and one it does not ask for is put back. When the run ends, everything still changed is put back. A run that switches to another device of the same platform puts the first one back before that device is shut down. To ask for a setting in every test in a file, call it in `beforeEach()`: `beforeEach(fn () => fixStatusBar());`.

`appearance()` takes `light` or `dark`. iOS runs `xcrun simctl ui <udid> appearance`. Android runs `adb shell cmd uimode night yes` or `no`.

`textSize()` takes one of five sizes. Each one is about the same scale on both platforms.

| Size | iOS content size | Android font scale |
| --- | --- | --- |
| `small` | `extra-small` | `0.85` |
| `default` | `large` | `1.0` |
| `large` | `extra-large` | `1.15` |
| `larger` | `extra-extra-extra-large` | `1.3` |
| `largest` | `accessibility-large` | `2.0` |

iOS runs `xcrun simctl ui <udid> content_size`. Android runs `adb shell settings put system font_scale`.

`location(51.5072, -0.1276)` takes a latitude, then a longitude. iOS runs `xcrun simctl location <udid> set 51.5072,-0.1276`. Android runs `adb emu geo fix -0.1276 51.5072`, longitude first. iOS cannot read a location back, so putting it back clears the simulated location. On Android, putting it back moves the emulator to the device's last GPS fix, from `adb shell dumpsys location`. A device has a fix only once an app has asked for one. With none, it moves to Google's Mountain View campus, the emulator's usual starting point.

`rotate()` takes `portrait` or `landscape`, and turns the device as a hand would, counter-clockwise for landscape. iOS calls `idb_companion`'s `set_orientation`. Android turns auto-rotate on with `adb shell settings put system accelerometer_rotation 1`, and points the emulator's gravity along its side with `adb emu sensor set acceleration`. A rotation lock would not turn a NativePHP app on Android: one that allows portrait and landscape follows the sensor and ignores the lock. Called on an open screen, the step waits until the app is drawn the other way round, for about 5 seconds at most. Called before `screen()`, it does not wait: the screen showing then may be the home screen, which does not turn on a phone, and `screen()` waits for the route anyway.

On Android, NativePHP 4.6 can crash when it is turned while it is still starting: it tells PHP about the new orientation before it has loaded the library that carries the message (`UnsatisfiedLinkError` in `NativeElementBridge.nativeElementWriteEvent`). Wait for the screen before turning it, as `screen('/')->assertSee('Home')->rotate('landscape')` does. That took about 2.5 seconds on an Android emulator, whose sensor settles before the screen turns, and half a second on an iOS Simulator. The app only turns to orientations it allows, set under `orientation` in `config/nativephp.php`; NativePHP allows only portrait on a phone by default. Putting it back puts back the device's own orientation, and on Android, its auto-rotate setting and gravity.

`fixStatusBar()` shows 9:41, full Wi-Fi, and a full battery, so screenshots match from run to run. iOS runs `xcrun simctl status_bar <udid> override`, which also shows full cellular bars. Android uses System UI's demo mode, with no cellular icon and no notification icons. On Android, an appearance or text size change redraws the status bar, and the redraw drops demo mode. So a fixed status bar is fixed again 2 seconds after that change, and each such change takes those 2 seconds longer.

Appearance, text size, and orientation are put back to what the device had before the run changed them, so a Simulator you keep in dark mode stays dark.

## Restart the app

These calls go on the screen.

```php
screen('/settings')
    ->type('Name', 'Sam')
    ->background(3)
    ->assertValue('Name', 'Sam')
    ->relaunch()
    ->openUrl('/settings/profile')
    ->assertSee('Profile');
```

`background(3)` goes to the home screen, waits 3 seconds, and brings the app back as it was. The app keeps running in the background. iOS presses Home through `idb_companion`, then runs `xcrun simctl launch`. Android presses Home, then starts the app's launcher activity, which brings its task back. Leave the seconds off to wait 1.

`relaunch()` stops the app and starts it again. The database is not copied again, so rows the app wrote stay. The app opens on its first screen. iOS runs `xcrun simctl launch --terminate-running-process`. Android runs `am force-stop`, then starts the launcher activity.

`openUrl('/settings/profile')` opens another route in the app that is already running, the way `screen()` does. It does not copy the database again. On Android, a link that no activity in the app takes starts the launcher activity instead. A link sent while the app is still starting can be lost: on iOS, an `openUrl()` straight after `screen()` stayed on the first route. Check the first screen before opening another one.

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

The iOS "Open in…" dialog and Android's "Wait" button are closed automatically. The "Open in…" dialog is found in whatever language the Simulator is set to, by the text iOS itself uses for it.

## Photos, Face ID, and push

`addPhoto()` puts an image or video in the device's photo library, so `pickPhoto()` has a known picture to choose. Call it before the first `screen()`, or on the screen. A relative path is read from the directory the tests run in. The photo stays in the library until `--wipe`.

```php
addPhoto('tests/fixtures/cat.jpg');

screen('/profile')
    ->tap('Change photo')
    ->pickPhoto();
```

iOS runs `xcrun simctl addmedia`. Android pushes the file to `/sdcard/Pictures` and asks the media scanner to list it.

`matchBiometric()` answers the Face ID or fingerprint prompt on screen with a match. `failBiometric()` answers it with a face or finger the device does not know. Tap the control that asks first.

```php
screen('/vault')
    ->tap('Unlock')
    ->matchBiometric()
    ->assertSee('Unlocked');
```

On iOS, both calls first enroll a face and a finger, then post the Simulator's own match or no-match notification for both sensors with `notifyutil`. An app often checks that something is enrolled before it offers Face ID at all. Call `enrollBiometrics()` before the first `screen()` for that app. Enrollment stays until `--wipe`.

On Android, `matchBiometric()` touches finger 1 with `adb emu finger touch`, and `failBiometric()` touches finger 99, which was never enrolled. adb cannot enroll a finger. Set a screen lock and enroll one in the emulator's Settings once, and the AVD keeps it.

`push()` delivers a push notification to the app, the way APNs would. It takes a title, a body, and custom keys for the app to read. It works on the iOS Simulator only. Android delivers push notifications through Firebase, and the emulator cannot fake one.

```php
screen('/orders')
    ->push('Order shipped', 'It arrives Tuesday', ['order' => 42])
    ->assertSee('Order 42 shipped');
```

iOS runs `xcrun simctl push` with this payload:

```json
{"aps": {"alert": {"title": "Order shipped", "body": "It arrives Tuesday"}, "sound": "default"}, "order": 42}
```

## When an assertion fails

A failed assertion writes `tree.json`, `screen.png`, `trace.json`, and `trace.txt`. A recording that is still running is stopped and kept. It also copies `laravel.log` out of the app: `Library/Application Support/storage/logs/laravel.log` on iOS, and `app_storage/persisted_data/storage/logs/laravel.log` on Android. Android also writes `logcat.txt` for that app id. The failure message lists each file it wrote, and it lists the controls on screen. When the last attempt to read the screen failed, for example because the app stopped answering, the message says so. A control with no label is named by its role, and by its accessibility id when it has one.

`trace.json` has every step the test took, from its first `screen()`: the call and its label, what it matched, where it touched, how many times it read the screen, the scrolls that brought a control on screen, any wait for a sheet or for scrolling to stop, how long the step took, and how it ended. A test that opens two screens has one trace. `trace.txt` is the same steps, one line each:

```text
it saves the form with ('ios:1:iPhone 17 Pro')

   +0.000s    2.314s  ok      open [demo://settings]
   +2.314s    0.912s  ok      tap [Save]: matched Button "Save" at 200,700; 3 reads (1 failed); scrolled down
   +3.226s   15.004s  failed  assertSee [Saved]: 38 reads; Did not see [Saved].
```

The first column is when the step started, from the start of the test. The second is how long it took.

A test can also end with an error from the device, such as a companion call or a command that ran out of time. Once the test has opened a screen, that error saves the trace too. The step that broke ends in `error`:

```text
   +3.226s   10.012s  error   tap [Save]: matched Button "Save" at 200,700; 1 read; Companion [hid] did not answer in time: Operation timed out after 10002 milliseconds with 0 bytes received
```

It saves `screen.png`, `tree.json`, and the logs as well, unless the device stopped answering. When the companion stopped, it skips `tree.json`, which comes from the companion on iOS. When a command ran out of time, it saves only the trace. The error lists each file it wrote. A test that expects the error, with `throws()`, saves nothing.

Each failure gets its own directory under `simulator-failures/`, named for when it failed.

## When the app throws

NativePHP catches an exception in a component, logs it, and draws its own "Something went wrong" screen. Without help, the test would only see a screen that never shows what it waits for, and fail at the timeout.

So the plugin reads the app's `laravel.log` as the test runs. The test's first `screen()` notes where the log ends. Every step reads what the app wrote after that, and a step that is waiting reads it once a second. An entry at `ERROR` or above ends the step that is under way when it lands, and the test fails with the exception:

```text
The app logged App\Exceptions\AlreadyPaid: Order 2 was paid already.

Thrown at app/NativeComponents/Checkout.php:31

#0 vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): App\NativeComponents\Checkout->pay()
#1 vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\Container\BoundMethod::{closure}()
```

The step ends `error` in the trace. The failure saves the trace, the tree, the screenshot, and the logs, as any error does, including the `daily` channel's file. Paths start from the app's own directory.

A tap only sends the touch. The app's PHP answers it a moment later, so the error a tap causes usually ends the step after it, such as the `assertSee()` that waited for the answer. A step that fails is read once more before it reports, so an assertion that gave up names the app's error, with its own failure after it. It is still a failed assertion, as PHPUnit counts it. A test whose last step is a tap, a keystroke, or a `screen()` reads the log for one second more as it ends, so that tap's error still fails it. It does not wait when an Http, Mail, or Event assert after that step has already waited for the app, nor after a screenshot or a recording. When the app shows the `nativephp-pending` marker, the wait ends as soon as the marker reads 0 twice in a row.

The logs read are `storage/logs/laravel.log` and, for the `daily` channel, the newest `laravel-2026-10-20.log`. Each is read from where it ended, so an app that writes both, or keeps other `laravel-*.log` channels beside them, never has an old entry read again. A channel that writes elsewhere is not read. Both Laravel's usual line format and Monolog's `JsonFormatter` are read: a JSON entry names the exception from its `context.exception`, with its stack when the formatter includes it. A channel with another formatter of its own is not understood, and its errors do not fail the test. Both are read in one call; on the Android emulator that takes about 0.04 seconds.

A test where the app is meant to log errors calls `allowAppErrors()`. A test that expects one exception names it:

```php
it('refuses to pay twice', function () {
    expectAppError(AlreadyPaid::class);

    screen('/orders')->tap('Pay')->tap('Pay');
});
```

That error does not fail the test, and neither does one that extends it. A test that ends without it fails. An error of another class still fails the test. With `app_errors` off, or in a test that never opened a `screen()`, `expectAppError()` fails at once and says why.

`Configuration::configure(['app_errors' => false])` stops reading the log in every test.

## Wait for the app, not the clock

A tap or a keystroke on a NativePHP screen goes to PHP, and PHP answers with the next screen. The plugin cannot see that round trip, so it waits by looking: until two reads of the screen match, until a sheet has had time to slide in, until a field reads the same twice. A slow handler, or a busy machine, can outlast every one of those.

An app can tell the plugin instead. When the screen has an element whose accessibility id is `nativephp-pending`, and whose label is how many events the app has sent PHP that PHP has not taken yet, the plugin:

- does not take a read as an answer while the count is above 0, and says so when a step times out that way;
- waits for 0 before it taps;
- types one character at a time, each once the field shows the one before and the count is 0. A `native:model` field typed in one burst loses keys when PHP answers late: an answer that arrives after the next keystroke writes the older value back. On iOS the field also sends that older value to PHP again, so the count never gets back to 0 and the field never settles. Android, where a late answer only loses keys, types in one burst first, and a character at a time only when that lost some.

A screen without that element is waited for as before. NativePHP does not show it yet. [docs/nativephp-idle-signal.md](docs/nativephp-idle-signal.md) has the change that adds it, for iOS and Android, and how it was checked on both.

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

`--record-failures` records every test and keeps the video only when the test fails. The clip starts at the test's first `screen()` and is written where `record()` writes it. `Configuration::configure(['record_failures' => true])` does the same for every run. A `record()` inside that test keeps the clip, and writes it to the path you passed. A `stopRecord()` ends the clip early, and it is still kept only when the test fails. Recording every test makes each one slower.

The trace shows what `type()` typed. Text typed into a password field shows as dots in the trace. On Android the log says how many characters the screen reader typed, not which. When `adb shell input` types instead, the `adb` command in the log has the text, a password too.

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

For your app's device tests on GitHub Actions, this repository has an action for each platform. Run each platform as its own job.

- `thelemon2020/pest-plugin-simulator/actions/android` boots an Android Emulator on a Linux runner. [`.github/workflows/devices.yml`](.github/workflows/devices.yml) runs the sample app's tests with it.
- `thelemon2020/pest-plugin-simulator/actions/ios` boots an iOS Simulator on a macOS runner. [`.github/workflows/devices.yml`](.github/workflows/devices.yml) runs the sample app's tests with it.

The examples pin a release tag, the first one with the actions. Pin a tag rather than `@main`, so a change on main can't change your CI. To update, change it to a newer release's tag.

### What your job does, and what the action does

Your job checks out the app, sets up a PHP for Composer, installs the Composer dependencies, and writes the `.env` the tests boot with. Each app does these its own way: private packages and their tokens, where `.env` comes from, and whether the tests copy a migrated SQLite database into the app. The `.env` needs an app key and the values under [Configure](#configure).

Then the action does the rest:

- It sets up the PHP that `nativephp.lock` names, with the extensions and memory the build needs. With any other PHP, `native:run` stops to ask which one to bundle, and nothing in CI answers. Pass `setup-php: false` to keep your own. The action still checks that it matches.
- It runs `native:install` for its platform, because `nativephp/` is not usually committed.
- It runs the tests with `--simulator-verbose`. The slowest commands and steps go on the run's summary page, and the logs are uploaded as the `<prefix>-timings` artifact. When the job fails, [what each failure wrote](#when-an-assertion-fails) and the device logs are uploaded as `<prefix>-failures`.

### Android

```yaml
name: devices

on:
  pull_request:
  push:
    branches: [main]

jobs:
  android:
    runs-on: ubuntu-latest
    timeout-minutes: 60
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: sqlite3, pdo_sqlite

      - name: Install the app
        run: |
          composer install --no-interaction --prefer-dist --no-progress
          cp .env.example .env
          php artisan key:generate
          touch database/database.sqlite
          php artisan migrate --force

      - uses: thelemon2020/pest-plugin-simulator/actions/android@v0.5.0
        with:
          tests: tests/Simulator
```

The action needs a Linux runner: the emulator boots in time only with KVM, which GitHub's Linux runners have. It turns KVM on, sets up Java 17 and Gradle's caches, and installs the Android SDK and the NDK the app builds with. Then it makes a new emulator. A new emulator has nothing installed, so the action passes `--rebuild`, and the plugin builds the app while the emulator boots. Gradle's caches are saved from the default branch only, and a pull request can only read those, so run the job on pushes to main too. On this repository's sample app, the whole job takes 7 to 9 minutes.

| Input | Default | |
|---|---|---|
| `app-directory` | `.` | The app's directory. |
| `tests` | | The test files or directories to run. Empty runs the suites `phpunit.xml` lists. |
| `pest-arguments` | | More arguments for Pest, such as `--filter=checkout`. `--android`, `--rebuild`, and `--simulator-verbose` are always passed. |
| `api-level` | `36` | The emulator's Android API level. A NativePHP 4.6 app needs 33 or newer. |
| `system-image` | `google_apis` | The emulator's system image, as in `system-images;android-36;google_apis;x86_64`. |
| `device-profile` | `pixel_7` | The device the emulator copies, from `avdmanager list device`. |
| `avd-name` | `Pixel` | The emulator's AVD name. A suite with an `->android([...])` list needs it to be one of those. |
| `setup-php` | `true` | Whether to set up PHP. |
| `php-version` | from `nativephp.lock` | The PHP to set up. With no `nativephp.lock` and no version, PHP is left as it is. |
| `php-extensions` | | More PHP extensions to set up, in setup-php's format. |
| `artifact-prefix` | `android` | The start of the artifacts' names. Give each job its own when one workflow runs several. |

The Android Emulator starts with no window and draws in software. If a boot does not finish, the error names the emulator log, and the action uploads that log with what failed.

### iOS

This repository's iOS job runs the sample app's tests with this action. GitHub's macOS runner is several times slower than a Mac, so the sample app gives each step 60 seconds on CI.

```yaml
jobs:
  ios:
    runs-on: macos-latest
    timeout-minutes: 90
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: sqlite3, pdo_sqlite

      - name: Install the app
        run: |
          composer install --no-interaction --prefer-dist --no-progress
          cp .env.example .env
          php artisan key:generate
          touch database/database.sqlite
          php artisan migrate --force

      - uses: thelemon2020/pest-plugin-simulator/actions/ios@v0.5.0
        with:
          tests: tests/Simulator
```

The action installs `idb_companion` 1.6.6, the version this repository's iOS job runs, from Facebook's release archive, checked against its checksum. Facebook's tap only offers the newest. It runs the tests on the newest iPhone the runner's Xcode has. Pass `--device` in `pest-arguments` for another. Its inputs are the Android action's, without the emulator ones: `app-directory`, `tests`, `pest-arguments`, `setup-php`, `php-version`, `php-extensions`, and `artifact-prefix`, which is `ios` by default. A macOS runner's minutes cost ten times a Linux runner's, so this repository runs its iOS job by hand and once a week.

### Parallel runs

```bash
vendor/bin/pest --ios --parallel
vendor/bin/pest --android --parallel
```

Each test is one row per device. ParaTest hands a worker a whole file. A parallel run that lists more than one device starts one lane per device, and every worker in that lane stays on it. The first lane also runs the rest of the suite. Each worker gets its own `idb_companion` port and its own booted device. An iOS worker reuses the Simulator it cloned last time (`iPhone 17 pest-1`) instead of copying a new one. In an action, pass `--parallel` in `pest-arguments`. This repository's device jobs run without it.

A test limited to a platform the suite does not run is skipped. A machine without Xcode and `idb_companion`, or without the Android SDK, skips the tests that needed that platform.

## Cleanup

When Pest finishes, it puts back the appearance, text size, location, orientation, and status bar the tests changed. Then it shuts down the Simulator, Emulator, and `idb_companion` that this run started. A device that was already booted stays up. An `idb_companion` already listening for this simulator is reused. One listening for a different simulator is left alone, and the plugin starts its own on the next port.

A run stopped with Ctrl+C or `SIGTERM`, or one that dies of a fatal error, shuts them down too. Catching the signal needs PHP's `pcntl` extension.

A `simctl`, `adb`, or other tool call that does not finish in 60 seconds is stopped, and the test fails naming that command. Listing, booting, erasing, and shutting down Simulators, granting permissions, and adding a photo get 10 minutes. `native:run` gets 30.
