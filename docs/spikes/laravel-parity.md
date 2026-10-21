# Spike: Laravel parity on the device

Before building database assertions, app-error failures, and Laravel's own fakes across the
process boundary, these questions were answered on the sample app, on real devices:

- iOS: iPhone 17 Pro Simulator, iOS 26.5, Xcode's simctl, a debug build from `native:run`.
- Android: the `Pixel` AVD (emulator-5554), a debug build from `native:run`.
- NativePHP mobile 4.6.0, nativephp/mobile-ui 0.8.0, Laravel 13.35, PHP 8.5 on the host.

A throwaway `/spike` screen had buttons that threw, fetched with `Http`, faked events, tapped,
and wrote rows. A throwaway line in this package's `ServiceProvider::boot()` wrote what it
saw to `storage/logs/spike-boot.log` on each boot. Neither is committed. NativePHP's source
in `vendor/nativephp/mobile` answered the rest.

## 1. Does an exception in a component reach laravel.log?

Yes, on both. NativePHP catches every exception from `mount()`, `render()`, and an event
handler (`NativeComponent::runLoop()`), hands it to `report()`, and draws its own error
screen over the app ("Something went wrong", the exception's class, its message, and its
stack when `app.debug` is on). The log line is Laravel's usual one:

```text
[2026-10-20 13:48:02] local.ERROR: spike boom {"exception":"[object] (RuntimeException(code: 0): spike boom at .../app/NativeComponents/Spike.php:35)
[stacktrace]
#0 .../vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): App\NativeComponents\Spike->boom()
```

**Decision:** the app error check reads laravel.log. It does not need to read the error
screen. The error screen's tree has no `testing_events`, so on both platforms the patched
idle marker (docs/nativephp-idle-signal.md) then reads busy for good: a test waiting on the
screen would time out, which is one more reason to fail on the log first.

## 2. Is a pull of the database consistent while the app writes? What does a pull cost?

A button inserted 4,000 rows, one commit each, about 170 commits a second. While it ran,
the test pulled `database/database.sqlite` 60 times with `adb exec-out run-as ... cat`.
Every copy passed `PRAGMA quick_check`, and the row counts only went up (3, 18, 33, ...,
1038). A `-journal` file was there on every pull. The app's database is in SQLite's default
`delete` journal mode on both platforms (`journal_mode` is null in `config/database.php`),
so there is no `-wal` file.

On the emulator one `run-as cat` of the 100 KB database took 0.037 to 0.042 seconds. One
`run-as tail -c +N` of laravel.log took 0.035 to 0.065, and `wc -c` and `tail` in one
`run-as sh -c` took 0.040. Two adb calls, the database and its journal, took about 0.1.

On iOS the data container is a directory on the Mac, so a pull is a file copy.

**Decision:** pull the database and its `-wal`, check the copy with `PRAGMA quick_check`,
and pull again when it fails. The hot `-journal` is not pulled: applying it would roll the
copy back to before a commit that may already have finished. The log is read with one
`run-as sh -c` that prints the size and the new bytes together.

## 3. Which log channel and file does the app write on the device?

`LOG_CHANNEL=stack`, `LOG_STACK=single`, so `storage/logs/laravel.log` under the app's
storage: `Library/Application Support/storage/logs/laravel.log` on iOS and
`app_storage/persisted_data/storage/logs/laravel.log` on Android. The bundled `.env` is the
project's own, minus the keys NativePHP cleans. The log survives the database copy, so a
test reads only what was written after it started.

## 4. Is this package's ServiceProvider booted in the app? How does code know it is in NativePHP?

Yes, on both. NativePHP drops dev dependencies only for a release build
(`RunsAndroid.php` `$excludeDevDependencies = $this->buildType !== 'debug'`, and iOS passes
`--no-dev` only with `--release`). The plugin forces `--build=debug`, so the plugin is in
the bundle's vendor directory and in `bootstrap/cache/packages.php`, and Laravel discovers
`NativePhp\Simulator\Laravel\ServiceProvider`.

It boots about eight times in the second after a launch, all in one process: NativePHP
runs artisan commands (migrations) through their own interpreters before it boots the
persistent runtime. `runningInConsole()` is true in every one of them, the persistent
runtime too, because the embed SAPI sets it. So it cannot tell the runtime apart.

What tells NativePHP apart from the host: `config('nativephp-internal.running')` is true,
`$_SERVER['NATIVEPHP_RUNNING']` and `getenv('NATIVEPHP_RUNNING')` are `'true'`, and the
`nativephp_element_publish()` function exists. None of them is set on the host.

**Decision:** the app side is inert unless `config('nativephp-internal.running')` (or the
environment variable) is set and `APP_ENV` is not production.

## 5. Is the app booted once, or per event?

Once per launch. NativePHP 4.6 runs `runtime.mode = persistent`: `persistent.php` boots
Laravel once, and each request goes through `Runtime::dispatch()`. More important: a native
screen is one request that never ends while the screen is up. `Route::native()` calls
`NativeRouter::start()`, which loops in `NativeComponent::runLoop()`, blocked in
`nativephp_element_wait_event()` between taps. Taps, back presses, and navigation to the
next screen all happen inside that one request. A deep link that arrives while the app
runs is a `__deeplink` native event in the same loop. The spike's taps all logged the same
pid, minutes apart.

So nothing that runs "after each request" (middleware, `RequestHandled`, `terminate()`)
runs between two taps.

**Decision (changes the plan):** the app side hooks into NativePHP's tree observers.
`Native\Mobile\Edge\TreeObservers::register()` takes a
`Native\Mobile\Edge\Contracts\TreeObserver`, which NativePHP documents as the extension
point for packages: its `event()` runs before each UI or native event is handled, and its
`tree()` after each render. The app applies a changed fakes file in `event()`, so a fake a
test sets after the first screen is in place before the next tap's handler or the next
screen's `mount()` runs. It writes the record file in `tree()`, after the handler and the
render. It also applies the fakes file when a route is matched (`RouteMatched`), which is
how fakes set before the first `screen()` reach the first screen's `mount()`.

Not when the app boots: the first launch after an install runs `config:cache` in an app of
its own, and on the emulator a fake `features.express` applied at that boot was cached, so
every later launch had it. That showed up as a CheckoutTest whose second test saw the first
test's config. A route is only matched in the app that serves screens. A fake stays in place across requests,
since the container is not rebuilt between them, so the app puts the real services back
when the fakes file goes away. Because the process is long-lived, the app clears PHP's stat
cache before it looks at the file, as `NativeRouter::takeScreenIntent()` does for its own.

## 6. Does `Http::get()` in a component go through Laravel's HTTP client, so `Http::fake()` intercepts it?

Yes. A handler that called `Http::fake(['spike.test/*' => Http::response(['rate' => 42])])`
and then `Http::get('https://spike.test/rates')` showed "Rate 42" on both platforms, and
`Illuminate\Http\Client\Events\RequestSending` and `ResponseReceived` were dispatched.

## 7. Which events does NativePHP dispatch? Does `Event::fake()` in the app break taps?

During the spike the app dispatched `Native\Mobile\Events\Screen\ScreenMounted` (NativePHP
also has `ScreenResumed`, `ScreenUnmounted`, and the bridge events under
`Native\Mobile\Events\*`, such as `Alert\ButtonPressed` and `Camera\PhotoTaken`), plus
Laravel's own `Illuminate\Database\Events\*`, `Illuminate\Http\Client\Events\*`, and
`creating: native.spike` for the view.

After `Event::fakeExcept([fn (string $name): bool => str_starts_with($name, 'Native\\')])`
in the app, the next tap was handled and drawn ("Tapped 2"). `EventFake` takes a closure in
its list of events to dispatch, so NativePHP's own events can stay real.

**Decision:** the app's `Event::fake()` always lets `Native\*` events through to the real
dispatcher.

## 8. Does the host's APP_KEY match the one in the app?

No. NativePHP always removes `APP_KEY` and `APP_PREVIOUS_KEYS` from the bundled `.env`
(`CleansEnvFile::$alwaysCleanedEnvKeys`), and the app makes its own key. The first 12 hex
characters of each key's SHA-256: host `f85caf1b1eb4`, iOS `ac03cd6a8280`, Android
`f537dc72e70b`.

**Decision:** closures in the fakes file are signed with a key the plugin makes for the
run and writes into the file. The app unserializes them with that key and puts its own
back after. The key is no secret: the file is only read by a debug build outside
production, from the app's own storage, which only the app, simctl, and `run-as` reach.

## 9. Do the fakes' internal properties hold across Laravel 11 to 13?

Read with reflection from 11.57.0, 12.70.0, and 13.38.0:

| Class | Properties the plugin reads | 11 | 12 | 13 |
| --- | --- | --- | --- | --- |
| `MailFake` | `mailables`, `queuedMailables` | yes | yes | yes |
| `NotificationFake` | `notifications` | yes | yes | yes |
| `EventFake` | `events`, `eventsToFake`, `eventsToDispatch` | yes | yes | yes |
| `QueueFake` | `jobs`, `jobsToFake`, `jobsToBeQueued` | yes | yes | yes |
| `BusFake` | `commands`, `commandsSync`, `commandsAfterResponse`, `jobsToFake`, `jobsToDispatch` | yes | yes | yes |
| `Http\Client\Factory` | `recorded`, `preventStrayRequests` | yes | yes | yes |
| `Http\Client\Factory` | `allowedStrayRequestUrls` | no | yes | yes |

The asserts on those classes grow from one version to the next (`assertSentTimes`,
`assertDispatchedOnce`, `assertQueuedOnce`), but each reads the same arrays, so a subclass
that fills them works with every version's asserts.

Every Laravel 11 release on Packagist is flagged by a security advisory, so Composer
refuses to install one unless the project turns the advisory block off.

**Decision:** read the properties with reflection. Http is the exception: the test side
records the arguments of `fake()`, `fakeSequence()`, `preventStrayRequests()`, and
`allowStrayRequests()` as they are called, rather than reading the closures Laravel keeps
in `stubCallbacks`.
