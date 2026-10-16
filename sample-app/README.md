# Sample app

A small NativePHP v4 app the plugin's own tests drive on real devices. Each screen exercises a kind of control: `/form`, `/controls`, `/tabs`, `/orders`, `/items`, `/sheet`, `/alerts`, `/slow`, and `/photos`. It installs the plugin from the directory above it, so its tests run the plugin in this checkout.

## Run it

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan native:install both
```

```bash
vendor/bin/pest tests/Feature
vendor/bin/pest tests/Simulator --ios
vendor/bin/pest tests/Simulator --android
```

`tests/Feature` renders every screen in-process with NativePHP's own test harness, in about a second. `tests/Simulator` boots a device: the first run builds the app, and `--rebuild` builds it again after the app's code changes.

Two tests skip themselves on purpose:

- `SlowTest` needs the app to show the idle marker described in `../docs/nativephp-idle-signal.md`, which NativePHP does not show yet. Without it, a field whose PHP takes 300ms per keystroke loses keys when typed in one burst.
- The screenshot test in `TabsTest` skips on CI. A baseline belongs to the machine that saved it.

`.env.example` sets `NATIVEPHP_APP_VERSION_CODE=1`, and `config/nativephp.php` reads it with `(int)`. NativePHP 4.6's iOS app compares the build number in its bundle with the one in the `.env` it unpacked. Without both, they never match, so the app unpacks its Laravel code and runs its migrations on every launch. Every test launches the app after copying the database, and on CI's macOS runner that took 10 to 20 seconds of each test's first 15-second step.

`.github/workflows/devices.yml` runs the Android tests on every pull request, and the iOS tests by hand or weekly.
