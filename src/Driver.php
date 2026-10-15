<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

/**
 * An element is one thing describe() read off the screen. The first four keys are always
 * there. Both drivers fill in the rest through AccessibilityTree::summarize(), but a driver
 * that builds elements another way, like the tests' fakes, can leave them out, so read
 * them with a default. A Tappable is an element with a center, which is what a match is.
 *
 * An OpenPrompt is what iOS's deep-link prompt says in one language: its titles as format
 * strings, with a placeholder such as `%@` where the app's name goes, and its Open button.
 *
 * @phpstan-type Element array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}, value?: ?string, enabled?: bool, selected?: bool, checked?: bool, chrome?: ?string, webview?: bool, carousel?: ?array{0: float, 1: float, 2: float, 3: float}, secure?: bool, frame?: ?array{0: float, 1: float, 2: float, 3: float}, range?: ?array{0: float, 1: float}, checkable?: bool}
 * @phpstan-type Tappable array{label: string, role: ?string, id: ?string, center: array{0: float|int, 1: float|int}, value?: ?string, enabled?: bool, selected?: bool, checked?: bool, chrome?: ?string, webview?: bool, carousel?: ?array{0: float, 1: float, 2: float, 3: float}, secure?: bool, frame?: ?array{0: float, 1: float, 2: float, 3: float}, range?: ?array{0: float, 1: float}, checkable?: bool}
 * @phpstan-type OpenPrompt array{titles: list<string>, open: string}
 */
interface Driver
{
    public function ensureReady(): void;

    public function open(string $url): void;

    /**
     * Closes system UI the last test left over the app, such as a photo picker or a
     * permission dialog, so the app comes to the front when the next test opens it.
     *
     * @param  bool  $stopping  whether the app is about to be stopped to copy the database
     */
    public function closeSystemUi(bool $stopping): void;

    /**
     * @return list<Element>
     */
    public function describe(?string $treePath = null): array;

    public function tap(float $x, float $y): void;

    public function press(float $x, float $y, float $seconds = 0.8): void;

    public function swipe(float $x1, float $y1, float $x2, float $y2, float $seconds = 0.3): void;

    public function back(): void;

    public function clear(int $characters = 40): void;

    /**
     * Presses the keyboard's return key in the focused field.
     */
    public function enter(): void;

    public function text(string $text): void;

    /**
     * Whether NativePHP's text field on this platform sends PHP an answer that came back
     * late as a new event. iOS's does, so a key typed before PHP answered the one before
     * can keep PHP busy for good (see Screen::paced()). Android's takes the answer without
     * sending it, which only loses keys.
     */
    public function resendsLateAnswers(): bool;

    /**
     * @return array{0: float, 1: float}
     */
    public function viewport(): array;

    /**
     * How tall the strip along the bottom of the viewport is where a tap goes to the system
     * rather than the app, or 0 when there is none.
     */
    public function homeIndicator(): float;

    public function screenshot(string $path): void;

    public function startRecording(string $path): void;

    public function stopRecording(): void;

    /**
     * @param  list<string>  $services
     */
    public function grant(array $services): void;

    /**
     * @param  list<string>  $services
     */
    public function revoke(array $services): void;

    /**
     * @return list<string>
     */
    public function captureLogs(string $directory): array;

    public function installDatabase(string $sqlitePath): void;

    /**
     * `light` or `dark`. Null puts back what the device had before the first change.
     */
    public function appearance(?string $mode): void;

    /**
     * A size DeviceState knows, such as `large`. Null puts back what the device had before
     * the first change.
     */
    public function textSize(?string $size): void;

    /**
     * @param  array{0: float, 1: float}|null  $coordinates  latitude and longitude, or null to stop simulating one
     */
    public function location(?array $coordinates): void;

    /**
     * Fixes the status bar at 9:41 with full signal and battery, or lets it go.
     */
    public function statusBar(bool $fixed): void;

    /**
     * Goes to the home screen, waits, and brings the app back.
     */
    public function background(float $seconds): void;

    /**
     * Stops the app and starts it again.
     */
    public function relaunch(): void;

    /**
     * Adds an image or video file to the device's photo library.
     */
    public function addMedia(string $path): void;

    /**
     * Answers the Face ID or fingerprint prompt on screen: `match` or `fail`. `enroll` makes
     * sure the device has a face or finger enrolled, which an app checks before it asks.
     */
    public function biometric(string $action): void;

    /**
     * Delivers a push notification to the app.
     *
     * @param  array<string, mixed>  $payload  an APNs payload, with its `aps` key
     */
    public function push(array $payload): void;

    /**
     * What the "Open in “App”?" prompt says in each language the device can show it in, or
     * nothing when the device has no such prompt or its text could not be read.
     *
     * @return list<OpenPrompt>
     */
    public function openPrompts(): array;

    /**
     * Whether the app was built and installed since the last time this was asked. Its next
     * launch is its first, and a NativePHP app unpacks its Laravel code and runs its
     * artisan commands then, before it draws anything.
     */
    public function freshlyInstalled(): bool;
}
