<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use NativePhp\Simulator\Exceptions\AmbiguousMatch;
use NativePhp\Simulator\Exceptions\NoMatch;
use NativePhp\Simulator\Exceptions\SimulatorException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

final class Screen
{
    public function __construct(
        private readonly Driver $driver,
        private readonly ElementFinder $finder = new ElementFinder,
        private readonly float $timeoutSeconds = 15.0,
        private readonly string $failureDirectory = '',
    ) {}

    /**
     * How many scrolls locate() tries before it says a control stayed off screen. Each one
     * moves at most half a screen, so this reaches about four screens away.
     */
    private const SCROLL_ATTEMPTS = 8;

    /**
     * A slow drag, like IosDriver::back(). A quick flick keeps the content moving after the
     * finger lifts (see settle()), which can carry a row straight past the screen.
     */
    private const SCROLL_SECONDS = 0.6;

    /**
     * How long after a sheet first shows up in a read to wait before touching the screen
     * (see presented()): twice the 0.4 seconds a tap into one took to land.
     */
    private const SHEET_SECONDS = 0.8;

    /**
     * When a read first had the sheet that is up now, or null when the last read had none.
     */
    private ?float $sheetSeen = null;

    /**
     * The public call under way, for the trace a failure saves (see Trace).
     *
     * @var array<string, mixed>|null
     */
    private ?array $step = null;

    public function tap(string $label): self
    {
        return $this->step('tap', $label, function () use ($label): void {
            $this->touch($this->locate($label, 'tap'));
        });
    }

    public function press(string $label, float $seconds = 0.8): self
    {
        return $this->step('press', $label, function () use ($label, $seconds): void {
            $match = $this->locate($label, 'press');
            $x = (float) $match['center'][0];
            $y = (float) $match['center'][1];
            $seconds = $this->seconds($seconds);
            $this->sent(['press', $x, $y, $seconds]);
            $this->driver->press($x, $y, $seconds);
        });
    }

    /**
     * `IosDriver::text()` paces the key events it sends (see its own doc comment), which
     * fixed a short single-word field outright but only reduced, not eliminated, apparent
     * truncation on a longer multi-word one under contention. Traced further than the
     * pacing fix alone: for a `native:model` field, every keystroke round-trips to PHP (an
     * embedded interpreter talking over shared memory, not a network request — see
     * NativeUITextInputCore.swift / NativeElementBridge.swift in the native project for the
     * real transport), and that round trip simply takes longer under host contention. A
     * controlled experiment (synthetic CPU load generated on purpose, moderate — not the
     * extreme end that briefly made the whole host unresponsive while calibrating this)
     * confirmed the value DOES eventually converge to the correct final string given long
     * enough to wait; it isn't stuck on a wrong one. So this isn't primarily about retrying
     * a corrupted attempt — `settled()`'s own window (see its doc comment) is what actually
     * matters here. The retry loop stays as a second line of defense for whatever this
     * window doesn't cover, but the main fix is giving the device realistic time to answer.
     */
    private const TYPE_ATTEMPTS = 3;

    public function type(string $label, string $text): self
    {
        return $this->step('type', $label, function () use ($label, $text): void {
            $match = $this->locate($label, 'type into');

            for ($attempt = 1; $attempt <= self::TYPE_ATTEMPTS; $attempt++) {
                $this->touch($match);
                $this->erase($this->replacementLength($match));

                if ($text === '') {
                    return;
                }

                // A password stays out of the trace, as it stays masked in tree.json.
                $this->sent(['text', ($match['secure'] ?? false) === true ? str_repeat('•', mb_strlen($text)) : $text]);
                $this->driver->text($text);
                $settled = $this->settled($label, $match, $text);
                $this->note('attempts', $attempt);
                $this->note('settled', $settled);

                if ($settled) {
                    return;
                }
            }
        });
    }

    /**
     * A short, bounded recheck — NOT the full `until()` deadline, which would starve every
     * OTHER step of its own share of the suite timeout if one `type()` call used all of it.
     *
     * This window used to be capped at 2 seconds, on the assumption that an unsettled value
     * meant something had gone wrong and needed a fresh attempt, not a longer wait. That
     * assumption was wrong: under real (not pathological) host contention — the kind an
     * ordinary video call or screen share produces on the same machine, confirmed with a
     * deliberately moderate synthetic CPU load, not the runaway extreme briefly hit while
     * calibrating that load — a `native:model` round trip can genuinely take tens of
     * seconds to come back, and the value DOES arrive correct once it does. Cutting the wait
     * short at 2 seconds and retrying was mostly just restarting a race that was already
     * going to finish correctly on its own, which is also why the retry version of this
     * method still weren't fully reliable: three retries of a 2-second window is still only
     * 6 seconds of real patience, not nearly enough for what we measured. 10 seconds is a
     * deliberately generous middle ground — long enough to cover ordinary contention, still
     * bounded so a field that's genuinely never going to settle doesn't hang the suite.
     *
     * Separately requires the value to match on two CONSECUTIVE reads, not just the first
     * one that happens to match — confirmed on real hardware that a single match isn't
     * always trustworthy: typing two `native:model` fields back to back, the SECOND
     * field's read occasionally matched once and then changed again right after, consistent
     * with a render still catching up even after the text first looked right.
     *
     * @param  array{label: string, role: ?string, id: ?string, center: array{0: float, 1: float}, value?: ?string}  $field
     */
    private function settled(string $label, array $field, string $text): bool
    {
        $deadline = microtime(true) + min(10.0, $this->timeoutSeconds);
        $confirmed = false;

        do {
            try {
                $elements = $this->read();
            } catch (SimulatorException) {
                $elements = [];
            }

            $matches = $elements !== [] && $this->finder->holds($elements, $label, $field, $text);

            if ($matches && $confirmed) {
                return true;
            }

            $confirmed = $matches;

            usleep(200_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function clear(string $label): self
    {
        return $this->step('clear', $label, function () use ($label): void {
            $match = $this->locate($label, 'clear');
            $this->touch($match);
            $this->erase($this->replacementLength($match));
        });
    }

    public function scroll(string $direction = 'down', ?float $distance = null, ?float $seconds = null): self
    {
        return $this->step('scroll', $direction, function () use ($direction, $distance, $seconds): void {
            $this->readRetrying();
            [$width, $height] = $this->driver->viewport();
            $this->drag(Gesture::scroll($direction, $width, $height, $distance), $seconds ?? 0.3);
        });
    }

    /**
     * scroll() until a control is on screen. A long list does not draw a row until it is
     * near the screen, so tap() alone cannot find one further down.
     *
     * Left and right drag a row that scrolls sideways: the only one on screen, or the row
     * that $from sits in.
     */
    public function scrollTo(string $label, string $direction = 'down', ?string $from = null): self
    {
        if (! in_array($direction, ['up', 'down', 'left', 'right'], true)) {
            throw new SimulatorException("Scroll [{$direction}] is not up, down, left, or right.");
        }

        if ($from !== null && ($direction === 'up' || $direction === 'down')) {
            throw new SimulatorException("scrollTo() drags from a control only to the left or right, not [{$direction}].");
        }

        return $this->step('scrollTo', $label, function () use ($label, $direction, $from): void {
            $row = null;

            if ($from !== null) {
                $match = $this->locate($from, 'scroll from');
                $row = [...$this->across($match), (float) $match['center'][1]];
            }

            $this->locate($label, 'scroll to', $direction, $row);
        });
    }

    public function swipe(string $direction, ?string $from = null, ?float $distance = null, ?float $seconds = null): self
    {
        return $this->step('swipe', $from === null ? $direction : "{$direction} from {$from}", function () use ($direction, $from, $distance, $seconds): void {
            $originX = null;
            $originY = null;

            if ($from !== null) {
                $match = $this->locate($from, 'swipe');
                $originX = (float) $match['center'][0];
                $originY = (float) $match['center'][1];
            } else {
                $this->readRetrying();
            }

            [$width, $height] = $this->driver->viewport();
            $this->drag(Gesture::swipe($direction, $width, $height, $originX, $originY, $distance), $seconds ?? 0.3);
        });
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $gesture
     */
    private function drag(array $gesture, float $seconds): void
    {
        [$x1, $y1, $x2, $y2] = $gesture;
        $seconds = $this->seconds($seconds);
        $this->presented();
        $this->sent(['swipe', $x1, $y1, $x2, $y2, $seconds]);
        $this->driver->swipe($x1, $y1, $x2, $y2, $seconds);
        $this->settle();
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

        $started = microtime(true);
        $deadline = $started + min(2.0, $this->timeoutSeconds);
        $previous = null;

        try {
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
        } finally {
            $this->tally('settleWait', microtime(true) - $started);
        }
    }

    public function goBack(): self
    {
        return $this->step('goBack', null, function (): void {
            $elements = $this->readRetrying();
            $button = $this->finder->navigationBack($elements);

            if ($button !== null) {
                $this->touch($button);

                return;
            }

            $this->presented();
            $this->sent(['back']);
            $this->driver->back();
        });
    }

    public function alert(string $label): self
    {
        return $this->step('alert', $label, function () use ($label): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->alertButton($elements, $label) !== null,
                "The alert did not offer [{$label}].",
            );
            $this->touch($this->finder->alertButton($elements, $label));
        });
    }

    public function share(?string $target = null): self
    {
        return $this->step('share', $target, function () use ($target): void {
            if ($target === null) {
                $elements = $this->until(
                    fn (array $elements): bool => $this->finder->sharing($elements),
                    'The share sheet was not open.',
                );
                $button = $this->finder->shareDismiss($elements);

                if ($button === null) {
                    $this->presented();
                    $this->sent(['back']);
                    $this->driver->back();

                    return;
                }

                $this->touch($button);

                return;
            }

            $elements = $this->until(
                fn (array $elements): bool => $this->finder->shareButton($elements, $target) !== null,
                "The share sheet did not offer [{$target}].",
            );
            $this->touch($this->finder->shareButton($elements, $target));
        });
    }

    public function pickPhoto(): self
    {
        return $this->step('pickPhoto', null, function (): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->firstPhoto($elements) !== null,
                'The photo picker had no image.',
            );
            $this->touch($this->finder->firstPhoto($elements));
            $confirm = $this->finder->photoConfirm($elements);

            if ($confirm === null) {
                $confirm = $this->finder->photoConfirm($this->read());
            }

            if ($confirm !== null) {
                $this->touch($confirm);
            }
        });
    }

    public function cancelPhoto(): self
    {
        return $this->step('cancelPhoto', null, function (): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->photoButton($elements, 'Cancel') !== null,
                'The photo picker had no [Cancel] button.',
            );
            $this->touch($this->finder->photoButton($elements, 'Cancel'));
        });
    }

    public function assertValue(string $label, string $value): self
    {
        return $this->step('assertValue', $label, function () use ($label, $value): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->hasValue($elements, $label, $value),
                "[{$label}] did not have value [{$value}].",
            );

            Assert::assertTrue($this->finder->hasValue($elements, $label, $value));
        });
    }

    public function assertEnabled(string $label): self
    {
        return $this->step('assertEnabled', $label, function () use ($label): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isEnabled($elements, $label, true),
                "[{$label}] was disabled.",
            );

            Assert::assertTrue($this->finder->isEnabled($elements, $label, true));
        });
    }

    public function assertDisabled(string $label): self
    {
        return $this->step('assertDisabled', $label, function () use ($label): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isEnabled($elements, $label, false),
                "[{$label}] was enabled.",
            );

            Assert::assertTrue($this->finder->isEnabled($elements, $label, false));
        });
    }

    public function assertChecked(string $label): self
    {
        return $this->step('assertChecked', $label, function () use ($label): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isChecked($elements, $label, true),
                "[{$label}] was unchecked.",
            );

            Assert::assertTrue($this->finder->isChecked($elements, $label, true));
        });
    }

    /**
     * The negative of assertChecked(), the same way assertNotSelected() is the negative of
     * assertSelected().
     */
    public function assertNotChecked(string $label): self
    {
        return $this->step('assertNotChecked', $label, function () use ($label): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isChecked($elements, $label, false),
                "[{$label}] was checked.",
            );

            Assert::assertTrue($this->finder->isChecked($elements, $label, false));
        });
    }

    /**
     * A `<native:chip>`'s own on/off state, which assertChecked() cannot answer — chips
     * report `.isSelected`, not the Switch-only `checked` value assertChecked() reads.
     */
    public function assertSelected(string $label): self
    {
        return $this->step('assertSelected', $label, function () use ($label): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isSelected($elements, $label, true),
                "[{$label}] was not selected.",
            );

            Assert::assertTrue($this->finder->isSelected($elements, $label, true));
        });
    }

    /**
     * The negative of assertSelected() — asserts a chip has NOT been toggled on, the same
     * way assertDisabled() is the negative of assertEnabled().
     */
    public function assertNotSelected(string $label): self
    {
        return $this->step('assertNotSelected', $label, function () use ($label): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isSelected($elements, $label, false),
                "[{$label}] was selected.",
            );

            Assert::assertTrue($this->finder->isSelected($elements, $label, false));
        });
    }

    public function assertNavTitle(string $title): self
    {
        return $this->step('assertNavTitle', $title, function () use ($title): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->navTitle($elements, $title),
                "The navigation title was not [{$title}].",
            );

            Assert::assertTrue($this->finder->navTitle($elements, $title));
        });
    }

    public function assertTabActive(string $label): self
    {
        return $this->step('assertTabActive', $label, function () use ($label): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->tabActive($elements, $label),
                "[{$label}] was not the active tab.",
            );

            Assert::assertTrue($this->finder->tabActive($elements, $label));
        });
    }

    public function assertNavigatedTo(string $path): self
    {
        return $this->step('assertNavigatedTo', $path, function () use ($path): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->navigatedTo($elements, $path),
                "Did not navigate to [{$path}].",
            );

            Assert::assertTrue($this->finder->navigatedTo($elements, $path));
        });
    }

    /**
     * One read checks every label. A later call reads the device again.
     */
    public function assertSee(string $text, string ...$others): self
    {
        return $this->step('assertSee', implode(', ', [$text, ...$others]), function () use ($text, $others): void {
            $texts = [$text, ...$others];

            $elements = $this->until(
                fn (array $elements): bool => $this->finder->seesAll($elements, $texts),
                fn (array $elements): string => $this->didNotSee($this->finder->missing($elements, $texts) ?: $texts),
            );

            Assert::assertTrue($this->finder->seesAll($elements, $texts));
        });
    }

    public function assertDontSee(string $text): self
    {
        return $this->step('assertDontSee', $text, function () use ($text): void {
            $elements = $this->until(
                fn (array $elements): bool => ! $this->finder->sees($elements, $text),
                "Still seeing [{$text}].",
            );

            Assert::assertFalse($this->finder->sees($elements, $text));
        });
    }

    public function screenshot(string $path): self
    {
        return $this->step('screenshot', $path, function () use ($path): void {
            $directory = dirname($path);

            if ($directory !== '' && $directory !== '.' && ! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $this->driver->screenshot($path);
        });
    }

    /**
     * Print the controls on screen, the way a failed assertion lists them.
     */
    public function dump(): self
    {
        return $this->step('dump', null, function (): void {
            echo $this->listing($this->readRetrying())."\n\n";
        });
    }

    public function record(?string $path = null): self
    {
        if (! Run::inside()) {
            throw new SimulatorException('record() only works inside a mobile() suite.');
        }

        return $this->step('record', $path, function () use ($path): void {
            Recording::request($path === '' ? null : $path);
            Recording::begin($this->driver, Run::device());
        });
    }

    public function stopRecord(): self
    {
        if (! Run::inside()) {
            throw new SimulatorException('stopRecord() only works inside a mobile() suite.');
        }

        return $this->step('stopRecord', null, function (): void {
            Recording::stop($this->driver);
        });
    }

    /**
     * Run one public call as a step of the test's trace. A call made inside another one
     * is part of that step.
     *
     * @param  Closure(): void  $body
     */
    private function step(string $action, ?string $target, Closure $body): self
    {
        if ($this->step !== null) {
            $body();

            return $this;
        }

        $this->step = ['action' => $action, 'target' => $target, 'started' => microtime(true), 'reads' => 0, 'failedReads' => 0];

        try {
            $body();
        } catch (Throwable $exception) {
            $this->endStep($exception instanceof AssertionFailedError ? 'failed' : 'error', $exception->getMessage());

            throw $exception;
        }

        $this->endStep('ok');

        return $this;
    }

    private function endStep(string $result, ?string $error = null): void
    {
        if ($this->step === null) {
            return;
        }

        $step = $this->step;
        $this->step = null;
        $step['seconds'] = microtime(true) - (float) $step['started'];
        $step['result'] = $result;

        if ($error !== null) {
            $step['error'] = $error;
        }

        Trace::add($step);
    }

    private function note(string $key, mixed $value): void
    {
        if ($this->step !== null) {
            $this->step[$key] = $value;
        }
    }

    private function tally(string $key, int|float $amount = 1): void
    {
        if ($this->step !== null) {
            $this->step[$key] = ($this->step[$key] ?? 0) + $amount;
        }
    }

    private function push(string $key, mixed $value): void
    {
        if ($this->step !== null) {
            $this->step[$key][] = $value;
        }
    }

    /**
     * @param  list<string|int|float>  $action  what went to the device: `['tap', x, y]`, `['text', 'abc']`
     */
    private function sent(array $action): void
    {
        $this->push('sent', $action);
    }

    /**
     * Tap a control's center, once any sheet has finished sliding in (see presented()).
     *
     * @param  array{center: array{0: float|int, 1: float|int}}  $element
     */
    private function touch(array $element): void
    {
        $this->presented();
        $x = (float) $element['center'][0];
        $y = (float) $element['center'][1];
        $this->sent(['tap', $x, $y]);
        $this->driver->tap($x, $y);
    }

    private function erase(int $characters): void
    {
        $this->sent(['clear', $characters]);
        $this->driver->clear($characters);
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
     * A step whose last read failed says why, rather than only that it saw nothing: an app
     * that has stopped answering (CollectShine's add-record form, spinning its main thread
     * after a scroll) otherwise reads as a control that is not there.
     *
     * @param  callable(list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}): bool>  $predicate
     * @param  string|Closure(list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}): string>  $failure
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>
     */
    private function until(callable $predicate, string|Closure $failure, ?float $deadline = null): array
    {
        $deadline ??= microtime(true) + $this->timeoutSeconds;
        $last = [];
        $unread = null;

        do {
            try {
                $last = $this->read();
                $unread = null;
            } catch (SimulatorException $exception) {
                $last = [];
                $unread = $exception;

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

        $message = $failure instanceof Closure ? $failure($last) : $failure;

        if ($unread !== null) {
            $message .= "\n\nThe last attempt to read the screen failed: ".$unread->getMessage();
        }

        $this->fail($message, $last, $unread);
    }

    /**
     * @param  list<string>  $labels
     */
    private function didNotSee(array $labels): string
    {
        $listed = implode(', ', array_map(
            fn (string $label): string => "[{$label}]",
            $labels,
        ));

        return "Did not see {$listed}.";
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
    private function fail(string $failure, array $elements, ?SimulatorException $unread = null): never
    {
        $this->endStep('failed', $failure);

        throw new AssertionFailedError($failure."\n\n".$this->listing($elements).$this->captureFailure($unread));
    }

    /**
     * The controls on screen, as a failure message and dump() list them.
     *
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>  $elements
     */
    private function listing(array $elements): string
    {
        $webview = $this->finder->hasWebView($elements)
            ? "\n\nA WebView is on screen. Blade and Livewire inside <webview> are outside the native accessibility tree."
            : '';

        return $this->finder->describe($elements).$webview;
    }

    /**
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>
     */
    private function read(): array
    {
        $elements = $this->describe();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $button = $this->finder->openDialogButton($elements);

            if ($button === null) {
                break;
            }

            $this->touch($button);

            if ($this->timeoutSeconds > 0) {
                usleep(800_000);
            }

            $elements = $this->describe();
        }

        $this->sheetSeen = $this->finder->hasSheet($elements) ? ($this->sheetSeen ?? microtime(true)) : null;

        return $elements;
    }

    /**
     * @return list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>
     */
    private function describe(): array
    {
        $this->tally('reads');

        try {
            return $this->driver->describe();
        } catch (SimulatorException $exception) {
            $this->tally('failedReads');

            throw $exception;
        }
    }

    /**
     * Wait out a sheet that is still sliding in before touching the screen.
     *
     * iOS drops every touch while a sheet presents. The tree cannot show it: from the first
     * read that has the sheet, every frame in it is already where the sheet will stop, so
     * two identical reads (settle()) pass at once. On an iPhone 17 Pro a tap into the sheet
     * landed from 0.4 seconds after that first read, and was dropped at 0.3.
     *
     * Closing a sheet needs no wait: the tree keeps the sheet until it has slid away, and a
     * tap sent the moment it left the tree landed.
     *
     * Every touch waits, not only one at a control locate() found: goBack(), alert(),
     * share(), and the photo picker find their control their own way, and the system
     * sheets and pickers they answer slide in like any other.
     */
    private function presented(): void
    {
        if ($this->sheetSeen === null || $this->timeoutSeconds <= 0) {
            return;
        }

        $left = $this->sheetSeen + self::SHEET_SECONDS - microtime(true);

        if ($left > 0) {
            $this->tally('sheetWait', $left);
            usleep((int) round($left * 1_000_000));
        }
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

    /**
     * Find a control, then scroll until its center is on screen.
     *
     * A scroll view reports rows below the fold, rows scrolled under a nav bar or tab bar,
     * and carousel cards past the screen's edge at their real positions. A match alone can
     * be a point past the glass or on a bar, and a tap there lands on nothing, or on the
     * bar, with no error. The next assertion then fails about something else.
     *
     * With $search set (scrollTo()), a label that is not in the tree yet scrolls that way
     * instead of waiting for it: down the screen, or left or right along $row (its left
     * edge, right edge, and height on screen). Every read and scroll shares one timeout.
     *
     * @param  array{0: float, 1: float, 2: float}|null  $row
     * @return array{label: string, role: ?string, id: ?string, center: array{0: float|int, 1: float|int}}
     */
    private function locate(string $label, string $action, ?string $search = null, ?array $row = null): array
    {
        $deadline = microtime(true) + $this->timeoutSeconds;
        $scrolls = 0;

        while (true) {
            $elements = $this->until(
                fn (array $visible): bool => $search !== null || $this->canMatch($visible, $label),
                "Could not find [{$label}] to {$action}.",
                $deadline,
            );

            try {
                $match = $this->finder->match($elements, $label);
            } catch (NoMatch) {
                $match = null;
            } catch (AmbiguousMatch $ambiguous) {
                $this->fail($ambiguous->getMessage(), $elements);
            }

            if ($match === null) {
                if (($search === 'left' || $search === 'right') && $row === null) {
                    $row = $this->onlyCarousel($label, $search, $elements);
                }

                [$width, $height] = $this->driver->viewport();
                $scroll = [null, $row === null
                    ? Gesture::scroll($search, $width, $height)
                    : Gesture::sideways($search, ...$row)];
            } else {
                $scroll = $this->toward($match, $elements);
            }

            if ($scroll === null) {
                $this->note('match', array_intersect_key($match, array_flip(['label', 'role', 'id', 'value', 'center'])));
                $this->presented();

                return $match;
            }

            [$where, $gesture] = $scroll;

            if ($gesture === null) {
                $this->fail("Found [{$label}] to {$action}, but it is {$where} the screen, and nothing it sits in scrolls sideways.", $elements);
            }

            if ($scrolls >= self::SCROLL_ATTEMPTS || ($scrolls > 0 && $this->timeoutSeconds > 0 && microtime(true) >= $deadline)) {
                $this->fail($where === null
                    ? "Scrolled {$search} ".($scrolls === 1 ? 'once' : "{$scrolls} times")." and did not find [{$label}]."
                    : "Found [{$label}] to {$action}, but it stayed {$where} the screen after ".($scrolls === 1 ? '1 scroll' : "{$scrolls} scrolls").'.',
                    $elements,
                );
            }

            $this->push('scrolls', match ($where) {
                'below' => 'down',
                'above' => 'up',
                'to the right of' => 'right',
                'to the left of' => 'left',
                default => (string) $search,
            });
            $this->drag($gesture, self::SCROLL_SECONDS);
            $scrolls++;
        }
    }

    /**
     * Where a control is off screen, and the drag that brings it toward the middle, or null
     * when it is already on screen. A nav bar or tab bar's own controls never move, so they
     * are never scrolled toward.
     *
     * Up and down come first. A card past the edge of the carousel it sits in is then
     * dragged sideways inside that carousel. A control past the edge of the screen in
     * nothing that scrolls sideways gets no drag (a null gesture): a sideways drag on a
     * list row can open its swipe actions.
     *
     * @param  array{label: string, role: ?string, id: ?string, center: array{0: float|int, 1: float|int}, chrome?: ?string, carousel?: ?array{0: float, 1: float, 2: float, 3: float}}  $match
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>  $elements
     * @return array{0: string, 1: array{0: float, 1: float, 2: float, 3: float}|null}|null
     */
    private function toward(array $match, array $elements): ?array
    {
        if (($match['chrome'] ?? null) !== null) {
            return null;
        }

        [$width, $height] = $this->driver->viewport();
        [$top, $bottom] = $this->visibleBand($elements, $height);
        $x = (float) $match['center'][0];
        $y = (float) $match['center'][1];

        if ($y < $top || $y > $bottom) {
            $distance = $this->span($y, ($top + $bottom) / 2, $height);

            return $y > $bottom
                ? ['below', Gesture::scroll('down', $width, $height, $distance)]
                : ['above', Gesture::scroll('up', $width, $height, $distance)];
        }

        [$left, $right] = $this->across($match);

        if ($x < $left || $x > $right) {
            $where = $x > $right ? 'to the right of' : 'to the left of';

            if (! is_array($match['carousel'] ?? null)) {
                return [$where, null];
            }

            $distance = $this->span($x, ($left + $right) / 2, $right - $left);

            return [$where, Gesture::sideways($x > $right ? 'right' : 'left', $left, $right, $y, $distance)];
        }

        return null;
    }

    /**
     * The part of the screen a control's carousel shows: its left and right edge, or the
     * whole width when the control is in no carousel.
     *
     * @param  array{carousel?: ?array{0: float, 1: float, 2: float, 3: float}}  $element
     * @return array{0: float, 1: float}
     */
    private function across(array $element): array
    {
        [$width] = $this->driver->viewport();
        $carousel = $element['carousel'] ?? null;

        if (! is_array($carousel)) {
            return [0.0, $width];
        }

        $left = max(0.0, (float) $carousel[0]);
        $right = min($width, (float) $carousel[0] + (float) $carousel[2]);

        return $left < $right ? [$left, $right] : [0.0, $width];
    }

    /**
     * The one row on screen that scrolls sideways, for scrollTo() left or right with no
     * control named to drag from.
     *
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}, carousel?: ?array{0: float, 1: float, 2: float, 3: float}}>  $elements
     * @return array{0: float, 1: float, 2: float}
     */
    private function onlyCarousel(string $label, string $direction, array $elements): array
    {
        [, $height] = $this->driver->viewport();
        [$top, $bottom] = $this->visibleBand($elements, $height);
        $rows = [];

        foreach ($elements as $element) {
            $carousel = $element['carousel'] ?? null;

            if (! is_array($carousel)) {
                continue;
            }

            $y = (float) $carousel[1] + (float) $carousel[3] / 2;

            if ($y >= $top && $y <= $bottom) {
                $rows[implode(',', $carousel)] = [...$this->across($element), $y];
            }
        }

        if (count($rows) !== 1) {
            $this->fail(($rows === [] ? 'Nothing on screen scrolls sideways' : 'More than one row on screen scrolls sideways')
                .", so scrollTo() cannot tell which row to drag. Name a control on that row: scrollTo('{$label}', '{$direction}', 'Item').", $elements);
        }

        return reset($rows);
    }

    /**
     * How far to drag, as a share of the screen, to bring a position to the middle. Half a
     * screen at most, like scroll(). Never so little that the drag is read as a tap.
     */
    private function span(float $position, float $middle, float $length): float
    {
        return max(0.1, min(0.5, abs($position - $middle) / $length));
    }

    /**
     * The part of the viewport no bar covers: from just under the nav bar to just over the
     * tab bar, or the home indicator when there is no tab bar. A row scrolled under a bar
     * is inside the glass, but a tap there lands on the bar, and one under the home
     * indicator lands on nothing. With no bar in the tree, it is the viewport above the
     * home indicator.
     *
     * The tree has a bar's controls, not the bar's frame. Their centers sit about half a bar
     * from its edge, which is the inset.
     *
     * @param  list<array{label: string, role: ?string, id: ?string, center: ?array{0: float|int, 1: float|int}}>  $elements
     * @return array{0: float, 1: float}
     */
    private function visibleBand(array $elements, float $height): array
    {
        $inset = $height * 0.03;
        $top = 0.0;
        $bottom = $height - $this->driver->homeIndicator();

        foreach ($elements as $element) {
            if (! in_array($element['chrome'] ?? null, ['navigation', 'tab'], true) || ! is_array($element['center'] ?? null)) {
                continue;
            }

            $y = (float) $element['center'][1];

            if ($y < $height / 2) {
                $top = max($top, $y + $inset);
            } else {
                $bottom = min($bottom, $y - $inset);
            }
        }

        return $top < $bottom ? [$top, $bottom] : [0.0, $height];
    }

    /**
     * @param  ?SimulatorException  $unread  why the last read failed, so a companion that stopped answering is not asked for the tree again
     */
    private function captureFailure(?SimulatorException $unread = null): string
    {
        $root = $this->failureDirectory !== '' ? $this->failureDirectory : FailureCapture::directory();

        return FailureCapture::listing($root, FailureCapture::save($this->driver, $root, $unread));
    }
}
