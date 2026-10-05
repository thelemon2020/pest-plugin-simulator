<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\AmbiguousMatch;
use NativePhp\Simulator\Exceptions\NoMatch;
use NativePhp\Simulator\Exceptions\SimulatorException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

final class Screen
{
    public function __construct(
        private readonly Driver $driver,
        private readonly ElementFinder $finder = new ElementFinder,
        private readonly float $timeoutSeconds = 15.0,
        private readonly string $failureDirectory = '',
    ) {}

    public function tap(string $label): self
    {
        $elements = $this->until(
            fn (array $visible): bool => $this->canMatch($visible, $label),
            "Could not find [{$label}] to tap.",
        );

        $match = $this->finder->match($elements, $label);
        $this->driver->tap((float) $match['center'][0], (float) $match['center'][1]);

        return $this;
    }

    public function press(string $label, float $seconds = 0.8): self
    {
        $elements = $this->until(
            fn (array $visible): bool => $this->canMatch($visible, $label),
            "Could not find [{$label}] to press.",
        );

        $match = $this->finder->match($elements, $label);
        $this->driver->press((float) $match['center'][0], (float) $match['center'][1], $this->seconds($seconds));

        return $this;
    }

    public function type(string $label, string $text): self
    {
        $elements = $this->until(
            fn (array $visible): bool => $this->canMatch($visible, $label),
            "Could not find [{$label}] to type into.",
        );

        $match = $this->finder->match($elements, $label);
        $this->driver->tap((float) $match['center'][0], (float) $match['center'][1]);
        $this->driver->clear($this->replacementLength($match));

        if ($text !== '') {
            $this->driver->text($text);
        }

        return $this;
    }

    public function clear(string $label): self
    {
        $elements = $this->until(
            fn (array $visible): bool => $this->canMatch($visible, $label),
            "Could not find [{$label}] to clear.",
        );

        $match = $this->finder->match($elements, $label);
        $this->driver->tap((float) $match['center'][0], (float) $match['center'][1]);
        $this->driver->clear($this->replacementLength($match));

        return $this;
    }

    public function scroll(string $direction = 'down', ?float $distance = null, ?float $seconds = null): self
    {
        $this->readRetrying();
        [$width, $height] = $this->driver->viewport();
        [$x1, $y1, $x2, $y2] = Gesture::scroll($direction, $width, $height, $distance);
        $this->driver->swipe($x1, $y1, $x2, $y2, $this->seconds($seconds ?? 0.3));
        $this->settle();

        return $this;
    }

    public function swipe(string $direction, ?string $from = null, ?float $distance = null, ?float $seconds = null): self
    {
        $originX = null;
        $originY = null;

        if ($from !== null) {
            $elements = $this->until(
                fn (array $visible): bool => $this->canMatch($visible, $from),
                "Could not find [{$from}] to swipe.",
            );
            $match = $this->finder->match($elements, $from);
            $originX = (float) $match['center'][0];
            $originY = (float) $match['center'][1];
        } else {
            $this->readRetrying();
        }

        [$width, $height] = $this->driver->viewport();
        [$x1, $y1, $x2, $y2] = Gesture::swipe($direction, $width, $height, $originX, $originY, $distance);
        $this->driver->swipe($x1, $y1, $x2, $y2, $this->seconds($seconds ?? 0.3));
        $this->settle();

        return $this;
    }

    private function seconds(float $seconds): float
    {
        if ($seconds <= 0) {
            throw new SimulatorException("Duration [{$seconds}] is not greater than 0.");
        }

        return $seconds;
    }

    /**
     * Wait for the accessibility tree to stop moving after a scroll or swipe.
     *
     * A swipe's own duration (Hid::swipe()'s $seconds, the length of the synthetic drag
     * itself) is not how long the CONTENT takes to stop moving: real scroll views keep
     * decelerating under their own momentum well after the drag ends, and the gRPC call
     * only blocks for the drag. A read() taken right after it returns can land mid-
     * deceleration, reporting an element's TRANSIENT position — and a tap() built from
     * that coordinate can miss by the time it reaches the device, because the content has
     * moved again in the meantime. This polls describe() until two consecutive reads
     * report the identical tree (settled) or a short cap elapses, so whichever call reads
     * next sees final, stable positions.
     *
     * Best-effort and bounded: a screen that is legitimately still changing (its own
     * animation, an unrelated poll tick) must not hang the caller waiting for two reads
     * that may never match — it simply stops waiting at the cap and lets the caller's own
     * retry loop (until()) sort out whether what's left is actually settled.
     */
    private function settle(): void
    {
        if ($this->timeoutSeconds <= 0) {
            return;
        }

        $deadline = microtime(true) + min(2.0, $this->timeoutSeconds);
        $previous = null;

        while (microtime(true) < $deadline) {
            try {
                $current = $this->driver->describe();
            } catch (SimulatorException) {
                return;
            }

            if ($previous !== null && $current === $previous) {
                return;
            }

            $previous = $current;
            usleep(100_000);
        }
    }

    public function goBack(): self
    {
        $elements = $this->readRetrying();
        $button = $this->finder->navigationBack($elements);

        if ($button !== null) {
            $this->driver->tap((float) $button['center'][0], (float) $button['center'][1]);
            return $this;
        }

        $this->driver->back();

        return $this;
    }

    public function alert(string $label): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->alertButton($elements, $label) !== null,
            "The alert did not offer [{$label}].",
        );
        $button = $this->finder->alertButton($elements, $label);
        $this->driver->tap((float) $button['center'][0], (float) $button['center'][1]);

        return $this;
    }

    public function share(?string $target = null): self
    {
        if ($target === null) {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->sharing($elements),
                'The share sheet was not open.',
            );
            $button = $this->finder->shareDismiss($elements);

            if ($button === null) {
                $this->driver->back();
                return $this;
            }

            $this->driver->tap((float) $button['center'][0], (float) $button['center'][1]);
            return $this;
        }

        $elements = $this->until(
            fn (array $elements): bool => $this->finder->shareButton($elements, $target) !== null,
            "The share sheet did not offer [{$target}].",
        );
        $button = $this->finder->shareButton($elements, $target);
        $this->driver->tap((float) $button['center'][0], (float) $button['center'][1]);

        return $this;
    }

    public function pickPhoto(): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->firstPhoto($elements) !== null,
            'The photo picker had no image.',
        );
        $photo = $this->finder->firstPhoto($elements);
        $this->driver->tap((float) $photo['center'][0], (float) $photo['center'][1]);
        $confirm = $this->finder->photoConfirm($elements);

        if ($confirm === null) {
            $confirm = $this->finder->photoConfirm($this->read());
        }

        if ($confirm !== null) {
            $this->driver->tap((float) $confirm['center'][0], (float) $confirm['center'][1]);
        }

        return $this;
    }

    public function cancelPhoto(): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->photoButton($elements, 'Cancel') !== null,
            'The photo picker had no [Cancel] button.',
        );
        $button = $this->finder->photoButton($elements, 'Cancel');
        $this->driver->tap((float) $button['center'][0], (float) $button['center'][1]);

        return $this;
    }

    public function assertValue(string $label, string $value): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->hasValue($elements, $label, $value),
            "[{$label}] did not have value [{$value}].",
        );

        Assert::assertTrue($this->finder->hasValue($elements, $label, $value));

        return $this;
    }

    public function assertEnabled(string $label): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->isEnabled($elements, $label, true),
            "[{$label}] was disabled.",
        );

        Assert::assertTrue($this->finder->isEnabled($elements, $label, true));

        return $this;
    }

    public function assertDisabled(string $label): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->isEnabled($elements, $label, false),
            "[{$label}] was enabled.",
        );

        Assert::assertTrue($this->finder->isEnabled($elements, $label, false));

        return $this;
    }

    public function assertChecked(string $label): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->isChecked($elements, $label, true),
            "[{$label}] was unchecked.",
        );

        Assert::assertTrue($this->finder->isChecked($elements, $label, true));

        return $this;
    }

    /**
     * A `<native:chip>`'s own on/off state, which assertChecked() cannot answer — chips
     * report `.isSelected`, not the Switch-only `checked` value assertChecked() reads.
     */
    public function assertSelected(string $label): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->isSelected($elements, $label, true),
            "[{$label}] was not selected.",
        );

        Assert::assertTrue($this->finder->isSelected($elements, $label, true));

        return $this;
    }

    /**
     * The negative of assertSelected() — asserts a chip has NOT been toggled on, the same
     * way assertDisabled() is the negative of assertEnabled().
     */
    public function assertNotSelected(string $label): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->isSelected($elements, $label, false),
            "[{$label}] was selected.",
        );

        Assert::assertTrue($this->finder->isSelected($elements, $label, false));

        return $this;
    }

    public function assertNavTitle(string $title): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->navTitle($elements, $title),
            "The navigation title was not [{$title}].",
        );

        Assert::assertTrue($this->finder->navTitle($elements, $title));

        return $this;
    }

    public function assertTabActive(string $label): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->tabActive($elements, $label),
            "[{$label}] was not the active tab.",
        );

        Assert::assertTrue($this->finder->tabActive($elements, $label));

        return $this;
    }

    public function assertNavigatedTo(string $path): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->navigatedTo($elements, $path),
            "Did not navigate to [{$path}].",
        );

        Assert::assertTrue($this->finder->navigatedTo($elements, $path));

        return $this;
    }

    /**
     * One read checks every label. A later call reads the device again.
     */
    public function assertSee(string $text, string ...$others): self
    {
        $texts = [$text, ...$others];
        $listed = implode(', ', array_map(
            fn (string $label): string => "[{$label}]",
            $texts,
        ));

        $elements = $this->until(
            fn (array $elements): bool => $this->seesAll($elements, $texts),
            "Did not see {$listed}.",
        );

        Assert::assertTrue($this->seesAll($elements, $texts));

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        $elements = $this->until(
            fn (array $elements): bool => ! $this->finder->sees($elements, $text),
            "Still seeing [{$text}].",
        );

        Assert::assertFalse($this->finder->sees($elements, $text));

        return $this;
    }

    public function screenshot(string $path): self
    {
        $directory = dirname($path);

        if ($directory !== '' && $directory !== '.' && ! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $this->driver->screenshot($path);

        return $this;
    }

    public function record(?string $path = null): self
    {
        if (! Run::inside()) {
            throw new SimulatorException('record() only works inside a mobile() suite.');
        }

        Recording::request($path === '' ? null : $path);
        Recording::begin($this->driver, Run::device());

        return $this;
    }

    public function stopRecord(): self
    {
        if (! Run::inside()) {
            throw new SimulatorException('stopRecord() only works inside a mobile() suite.');
        }

        Recording::stop($this->driver);

        return $this;
    }

    /**
     * read(), tolerant of a companion that has not stabilized yet.
     *
     * Every OTHER method that reads before it has something to look for goes through
     * until(), whose retry loop already absorbs a transient SimulatorException from
     * accessibility_info — e.g. `window-server frontmost returned no application object`,
     * seen when a read lands in the brief window right after screen() has just opened a
     * brand new screen and the companion has not yet resolved which app is frontmost.
     * scroll(), swipe(), and goBack() have no predicate to retry against, so their own
     * pre-gesture read() used to throw straight out of exactly that window — reproduced by
     * calling scroll() as the very first action after screen() opens, with no assertion in
     * between to let until()'s retry ride it out first.
     *
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>
     */
    private function readRetrying(): array
    {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (true) {
            try {
                return $this->read();
            } catch (SimulatorException $exception) {
                if ($this->timeoutSeconds <= 0 || microtime(true) >= $deadline) {
                    throw $exception;
                }

                usleep(400_000);
            }
        }
    }

    /**
     * @param  callable(list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}): bool>  $predicate
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>
     */
    private function until(callable $predicate, string $failure): array
    {
        $deadline = microtime(true) + $this->timeoutSeconds;
        $last = [];

        do {
            try {
                $last = $this->read();
            } catch (SimulatorException) {
                $last = [];

                if ($this->timeoutSeconds <= 0) {
                    break;
                }

                usleep(400_000);

                continue;
            }

            try {
                if ($predicate($last)) {
                    return $last;
                }
            } catch (AmbiguousMatch $ambiguous) {
                $this->fail($ambiguous->getMessage(), $last);
            }

            if ($this->timeoutSeconds <= 0) {
                break;
            }

            usleep(400_000);
        } while (microtime(true) < $deadline);

        $this->fail($failure, $last);
    }

    /**
     * @param  array{value?: ?string}  $match
     */
    private function replacementLength(array $match): int
    {
        $value = $match['value'] ?? null;

        return max(40, is_string($value) ? mb_strlen($value) : 0);
    }

    /**
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>  $elements
     */
    private function fail(string $failure, array $elements): never
    {
        $webview = $this->finder->hasWebView($elements)
            ? "\n\nA WebView is on screen. Blade and Livewire inside <webview> are outside the native accessibility tree."
            : '';

        throw new AssertionFailedError($failure."\n\n".$this->finder->describe($elements).$webview.$this->captureFailure());
    }

    /**
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>
     */
    private function read(): array
    {
        $elements = $this->driver->describe();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $button = $this->finder->openDialogButton($elements);

            if ($button === null) {
                return $elements;
            }

            $this->driver->tap((float) $button['center'][0], (float) $button['center'][1]);

            if ($this->timeoutSeconds > 0) {
                usleep(800_000);
            }

            $elements = $this->driver->describe();
        }

        return $elements;
    }

    /**
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>  $elements
     * @param  list<string>  $texts
     */
    private function seesAll(array $elements, array $texts): bool
    {
        foreach ($texts as $text) {
            if (! $this->finder->sees($elements, $text)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>  $elements
     */
    private function canMatch(array $elements, string $label): bool
    {
        try {
            $this->finder->match($elements, $label);

            return true;
        } catch (NoMatch) {
            return false;
        }
    }

    private function captureFailure(): string
    {
        $root = $this->failureDirectory !== ''
            ? $this->failureDirectory
            : getcwd().'/simulator-failures/'.date('Ymd-His');

        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

        $saved = [];

        try {
            $this->driver->describe($root.'/tree.json');
        } catch (SimulatorException) {
        }

        if (is_file($root.'/tree.json')) {
            $saved[] = $root.'/tree.json';
        }

        try {
            $this->driver->screenshot($root.'/screen.png');
        } catch (SimulatorException) {
        }

        if (is_file($root.'/screen.png')) {
            $saved[] = $root.'/screen.png';
        }

        try {
            foreach ($this->driver->captureLogs($root) as $path) {
                if (is_file($path)) {
                    $saved[] = $path;
                }
            }
        } catch (SimulatorException) {
        }

        if ($saved === []) {
            return "\n\nSaved {$root}";
        }

        return "\n\n".implode("\n", array_map(
            fn (string $path): string => "Saved {$path}",
            $saved,
        ));
    }
}
