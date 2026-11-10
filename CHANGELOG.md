# Changelog

From 1.0 this package follows [semantic versioning](https://semver.org). The README's [Versions](README.md#versions) section says what counts as the API. Each release's full notes are on [GitHub](https://github.com/thelemon2020/pest-plugin-simulator/releases).

## Unreleased

### Changed

- **The API is set out for 1.0.** The README's Versions section lists it: the functions, `Screen`, the suite `mobile()` returns, `Configuration::configure()`, the exceptions, the command-line options, the environment variables, and the GitHub Actions' inputs. Every other class is now marked `@internal`.
- **If you wrote your own `Driver`, or extended `Command`,** they are internal now. They can change in any release.
- **The sample app and CI run NativePHP 4.7 (#67).** 4.6 is no longer run. From 4.7, NativePHP skips a frame that looks the same as the last one; the plugin lets each frame go out while a test's fakes are in place, so the app's fakes still record after every render.
- **The "Early days" note is gone from the README.**

### Fixed

- **`rotate()` turns the iOS Simulator when idb cannot say how it is turned (#69).** On GitHub's macOS 26 runner, `get_orientation` fails. The plugin now turns the Simulator anyway, and puts back portrait at the end.

## [0.6.1] - 2026-11-02

### Fixed

- The database asserts, and a matching `assertScreenshotMatches()`, count as assertions (#63).
- An interrupted run stops the commands it was waiting on (#64).

## [0.6.0] - 2026-11-01

### Added

- Record a test by clicking through the app: `php artisan nativephp:simulator codegen` (#62).
- Database asserts against the app's own database, and `deviceDatabase()` (#48, #55).
- An error the app logs fails the step it happened in. `allowAppErrors()` and `expectAppError()` opt out (#49, #56).
- Laravel's fakes run in the app (#50, #51, #59).
- `rotate()` (#60) and `assertAccessible()` (#61).

## [0.5.0] - 2026-10-20

### Added

- GitHub Actions that set up the Android Emulator or the iOS Simulator and run the tests (#43, #44).

### Fixed

- iOS fixes from getting this repository's iOS job to pass on GitHub's macOS runner (#44).

## [0.4.0] - 2026-10-15

### Changed

- iOS reuses the installed build, as Android does. Pass `--rebuild` after changing the app's code (#35).
- Each test's first `screen()` closes what the last test left open (#33).
- Custom drivers have three new methods: `closeSystemUi()`, `resendsLateAnswers()` and `freshlyInstalled()`.

### Fixed

- Faster first screens, and steadier first launches on CI (#34, #39, #42).

## [0.3.0] - 2026-10-14

### Added

- Calls for every control NativePHP renders, `within()` and `nth()`, photos, Face ID and fingerprint prompts, push notifications, and screenshot comparison.
- `--simulator-verbose`, and a step trace saved with every failure.

### Changed

- `ext-curl` is required. `assertScreenshotMatches()` needs `ext-gd`.
- See the [0.3.0 release notes](https://github.com/thelemon2020/pest-plugin-simulator/releases/tag/v0.3.0) for the rest of the upgrade steps.

## Earlier

Releases before 0.3.0 are on [GitHub](https://github.com/thelemon2020/pest-plugin-simulator/releases).

[0.6.1]: https://github.com/thelemon2020/pest-plugin-simulator/releases/tag/v0.6.1
[0.6.0]: https://github.com/thelemon2020/pest-plugin-simulator/releases/tag/v0.6.0
[0.5.0]: https://github.com/thelemon2020/pest-plugin-simulator/releases/tag/v0.5.0
[0.4.0]: https://github.com/thelemon2020/pest-plugin-simulator/releases/tag/v0.4.0
[0.3.0]: https://github.com/thelemon2020/pest-plugin-simulator/releases/tag/v0.3.0
