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

/**
 * @phpstan-import-type Element from Driver
 * @phpstan-import-type Tappable from Driver
 */
final class Screen
{
    /**
     * How long the app's first launch after an install has to show its first screen. A
     * NativePHP app unpacks its Laravel code and runs its artisan commands before it draws
     * anything: on CI's x86_64 Android emulator the sample app's first screen came 15.5
     * seconds after its launch, and a tap with the 15 second timeout gave up first.
     */
    public const FIRST_LAUNCH_SECONDS = 60.0;

    /**
     * Until when a step may wait past its timeout, for the app's first launch after an
     * install: 0 when this screen did not launch a freshly installed app.
     */
    private readonly float $launching;

    /**
     * @param  float  $firstLaunchSeconds  how long the app has to show its first screen, when this screen launched it for the first time since it was installed
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly ElementFinder $finder = new ElementFinder,
        private readonly float $timeoutSeconds = 15.0,
        private readonly string $failureDirectory = '',
        float $firstLaunchSeconds = 0.0,
    ) {
        $this->launching = $firstLaunchSeconds > 0 ? microtime(true) + $firstLaunchSeconds : 0.0;
    }

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
     * How many events the app has sent to PHP that PHP has not taken, as the last read's
     * IdleMarker said. Null when the app shows no marker, and every wait is by looking.
     */
    private ?int $pending = null;

    /**
     * Whether idle() is waiting. read() taps iOS's "Open in" prompt through touch(), which
     * waits through idle(), which reads: one wait is enough.
     */
    private bool $idling = false;

    /**
     * When a read first had the sheet or alert that is up now, or null when the last read
     * had neither.
     */
    private ?float $sheetSeen = null;

    /**
     * The public call under way, for the trace a failure saves (see Trace).
     *
     * @var array<string, mixed>|null
     */
    private ?array $step = null;

    /**
     * The controls this screen looks inside, outermost first (see within()).
     *
     * @var list<string>
     */
    private array $scopes = [];

    /**
     * Everything the last read had, before within() narrowed it.
     *
     * @var list<Element>
     */
    private array $screenElements = [];

    /**
     * The within() control the last read could not find, if one was missing.
     */
    private ?string $scopeMissing = null;

    /**
     * nth() for the next call, then for the call under way, and whether that call used it.
     */
    private ?int $nth = null;

    private ?int $stepNth = null;

    private bool $nthUsed = false;

    /**
     * Look only inside one control. $callback gets this screen narrowed to the controls whose
     * center sits inside that control's frame: a card, a row, or a sheet, named by its label
     * or accessibility id. The control is found again on every read, so it can move as the
     * screen scrolls. This screen, which within() returns, still looks at everything.
     *
     * @param  Closure(self): mixed  $callback
     */
    public function within(string $container, Closure $callback): self
    {
        if ($this->nth !== null) {
            throw new SimulatorException('nth() counts the controls the next call matches. Call it inside within(), on the call it is for.');
        }

        $scoped = clone $this;
        $scoped->scopes[] = $container;
        $callback($scoped);

        return $this;
    }

    /**
     * The next call takes the nth control its label matches, counting from 1 in reading
     * order (top to bottom, then left to right), instead of failing when several match.
     */
    public function nth(int $index): self
    {
        if ($index < 1) {
            throw new SimulatorException("nth() counts from 1, not [{$index}].");
        }

        $this->nth = $index;

        return $this;
    }

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

                // For an app that says how far behind PHP is, a key at a time, each once PHP has
                // answered the last: from the start where a burst that outruns PHP can never
                // settle (paced()), and on a retry where it only lost keys, as a burst is quicker.
                if ($this->pending !== null && ($attempt > 1 || $this->driver->resendsLateAnswers())) {
                    $this->note('paced', true);
                    $this->paced($label, $match, $text);
                } else {
                    $this->driver->text($text);
                }

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
     * Types one character at a time, for an app that shows the IdleMarker: the first once
     * PHP has taken every event the app sent it, the erase before it included, and each one
     * after once the field shows the one before and PHP has taken that too.
     *
     * A `native:model` field sends PHP an event for each keystroke and writes back what PHP
     * answers. Typed in one burst, an answer that comes back after the next keystroke writes
     * an older value over the field. On iOS that is worse than a lost key: NativePHP's text
     * field sends PHP the older value it was given as a new event, whose answer is older
     * than the field again, so the events go round for good. On an iPhone 17 Pro Simulator,
     * after a burst of "hello there" into the sample app's field whose PHP takes 300ms an
     * event, the marker stayed at 11 for a minute while the field showed one prefix after
     * another, and a retry had nothing to wait for. Android's field takes the older value
     * without sending it, so a burst there only loses keys. With nothing still on its way to
     * PHP when a key goes in, there is no older answer to write back.
     *
     * Nothing says how long a field's PHP takes before it is typed into, so on iOS every
     * field of an app that shows the marker is typed this way. A key whose answer is back by
     * the next read costs that one read, about 0.25 seconds there. On Android, where a read
     * costs more and a burst that lost keys can be typed again, only a retry is.
     *
     * @param  Tappable  $field
     */
    private function paced(string $label, array $field, string $text): void
    {
        $this->answered(fn (): bool => true);
        $typed = '';

        foreach (mb_str_split($text) as $character) {
            $typed .= $character;
            $this->driver->text($character);

            // A read trims what a field holds, so after a space at either end the field
            // reads as it did before the key. Two reads in a row stand in for seeing it land:
            // waiting to see "Ada " ran out all 10 seconds.
            $shown = trim($typed);
            $this->answered(
                fn (array $elements): bool => $this->finder->holds($elements, $label, $field, $typed, $this->stepNth)
                    || $this->finder->holds($elements, $label, $field, $shown, $this->stepNth),
                $shown === $typed ? 1 : 2,
            );
        }
    }

    /**
     * Reads until PHP has taken every event the app sent it and $times reads in a row show
     * what $shows looks for, for up to 10 seconds.
     *
     * @param  Closure(list<Element>): bool  $shows
     */
    private function answered(Closure $shows, int $times = 1): void
    {
        $deadline = microtime(true) + min(10.0, $this->timeoutSeconds);
        $shown = 0;

        do {
            try {
                $elements = $this->read();
            } catch (SimulatorException) {
                $elements = [];
            }

            $shown = ! $this->busy() && $elements !== [] && $shows($elements) ? $shown + 1 : 0;

            if ($shown >= $times) {
                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);
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
     * A read trims what a field holds, so text with a space at either end only ever reads
     * back trimmed: "Ada " never matched, and type() used all three attempts on it. The
     * trimmed text counts, on two reads in a row even when the app's marker says PHP has
     * caught up, because it is also what the field showed before the space went in.
     *
     * @param  Tappable  $field
     */
    private function settled(string $label, array $field, string $text): bool
    {
        $deadline = microtime(true) + min(10.0, $this->timeoutSeconds);
        $confirmed = false;
        $trimmed = trim($text);

        do {
            try {
                $elements = $this->read();
            } catch (SimulatorException) {
                $elements = [];
            }

            $exact = $elements !== [] && $this->finder->holds($elements, $label, $field, $text, $this->stepNth);
            $matches = $exact || ($trimmed !== $text && $elements !== [] && $this->finder->holds($elements, $label, $field, $trimmed, $this->stepNth));

            // With the app's marker saying PHP has taken everything it was sent, there is no
            // answer still on its way to change the field again: one read is enough.
            if ($matches && ($confirmed || ($exact && $this->pending !== null && ! $this->busy()))) {
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
                    [$current, $this->pending] = IdleMarker::split($this->driver->describe());
                } catch (SimulatorException) {
                    return;
                }

                if ($previous !== null && $current === $previous && ! $this->busy()) {
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

    /**
     * Opens another route in the app that is already running, as screen() does, without
     * copying the database again.
     */
    public function openUrl(string $path): self
    {
        return $this->step('openUrl', $path, function () use ($path): void {
            $url = Configuration::resolve()->urlFor($path);
            $this->sent(['open', $url]);
            $this->driver->open($url);
        });
    }

    /**
     * Goes to the home screen, waits, and brings the app back as it was.
     */
    public function background(float $seconds = 1.0): self
    {
        return $this->step('background', (string) $seconds, function () use ($seconds): void {
            $this->sent(['background', $seconds]);
            $this->driver->background($seconds);
        });
    }

    /**
     * Stops the app and starts it again. The database keeps what the app wrote.
     */
    public function relaunch(): self
    {
        return $this->step('relaunch', null, function (): void {
            $this->sent(['relaunch']);
            $this->driver->relaunch();
        });
    }

    /**
     * press(), by the name NativePHP's own test harness gives it.
     */
    public function longPress(string $label, float $seconds = 0.8): self
    {
        return $this->press($label, $seconds);
    }

    /**
     * goBack(), by the name NativePHP's own test harness gives it.
     */
    public function pressBack(): self
    {
        return $this->goBack();
    }

    /**
     * type(), by the name NativePHP's own test harness gives it.
     */
    public function input(string $label, string $text): self
    {
        return $this->type($label, $text);
    }

    /**
     * Presses the keyboard's return key in a field, as a person does to send a search or a
     * message. With $text, types it first.
     */
    public function submit(string $label, ?string $text = null): self
    {
        return $this->step('submit', $label, function () use ($label, $text): void {
            $field = $this->locate($label, 'submit');

            if ($text !== null) {
                $this->type($label, $text);
            }

            // Android closes the keyboard once type() is done, which leaves the field without
            // focus, and a return key pressed then goes nowhere. A tap gives it focus back.
            // It goes where the field was found before typing: a field named by its
            // placeholder has no name once text is in it.
            $this->touch($field);
            $this->sent(['enter']);
            $this->driver->enter();
        });
    }

    /**
     * Flips a switch. toggle('Wi-Fi', true) turns it on, and taps only when it is off. Either
     * way, it waits until the switch reads what it should.
     */
    public function toggle(string $label, ?bool $on = null): self
    {
        return $this->step('toggle', $label, fn () => $this->turn($label, $on, 'toggle'));
    }

    /**
     * Checks a checkbox, or unchecks it with false, tapping only when it is the other way.
     */
    public function check(string $label, bool $checked = true): self
    {
        $action = $checked ? 'check' : 'uncheck';

        return $this->step($action, $label, fn () => $this->turn($label, $checked, $action));
    }

    public function uncheck(string $label): self
    {
        return $this->check($label, false);
    }

    private function turn(string $label, ?bool $on, string $action): void
    {
        $match = $this->locate($label, $action);
        $current = ($match['checked'] ?? false) === true;
        $wanted = $on ?? ! $current;
        $this->note('wanted', $wanted ? 'on' : 'off');

        if ($current !== $wanted) {
            $this->touch($match);
        }

        $this->until(
            fn (array $elements): bool => $this->finder->isChecked($elements, $label, $wanted, $this->stepNth),
            "[{$label}] stayed ".($wanted ? 'off' : 'on').'.',
        );
    }

    /**
     * Opens a picker and chooses an option: a `<native:select>`, or any control that opens
     * a menu. The option is one that appeared when the picker opened, so the picker's own
     * label for the current choice is never what gets tapped.
     */
    public function select(string $label, string $option): self
    {
        return $this->step('select', "{$label}: {$option}", function () use ($label, $option): void {
            $picker = $this->locate($label, 'select from');
            $before = array_map(fn (array $element): string => $this->placed($element), $this->screenElements);
            $this->touch($picker);
            $this->until(
                fn (): bool => $this->appeared($before, $option) !== null,
                "[{$label}] did not offer [{$option}].",
            );

            /** @var Tappable $choice until() waited for it */
            $choice = $this->appeared($before, $option);
            $this->touch($choice);
        });
    }

    /**
     * @param  Element  $element
     */
    private function placed(array $element): string
    {
        return $element['label'].'@'.json_encode($element['center'] ?? null);
    }

    /**
     * The option named $option among what was not on the screen before the picker opened.
     *
     * @param  list<string>  $before
     * @return Tappable|null
     */
    private function appeared(array $before, string $option): ?array
    {
        $fresh = array_values(array_filter(
            $this->screenElements,
            fn (array $element): bool => ! in_array($this->placed($element), $before, true),
        ));

        try {
            return $this->finder->match($fresh, $option);
        } catch (NoMatch|AmbiguousMatch) {
            return null;
        }
    }

    /**
     * Picks a radio button: the option named $option nearest below the group's label. It
     * waits until that option reads as selected.
     */
    public function selectRadio(string $group, string $option): self
    {
        return $this->step('selectRadio', "{$group}: {$option}", function () use ($group, $option): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->radio($elements, $group, $option) !== null,
                "Could not find [{$option}] under [{$group}].",
            );

            /** @var Tappable $radio until() waited for it */
            $radio = $this->radio($elements, $group, $option);
            $this->touch($radio);
            $this->until(
                fn (array $elements): bool => $this->radioSelected($elements, $group, $option),
                "[{$option}] under [{$group}] was not selected.",
            );
        });
    }

    /**
     * @param  list<Element>  $elements
     * @return Tappable|null
     */
    private function radio(array $elements, string $group, string $option): ?array
    {
        $below = $this->below($elements, $group);

        try {
            return $below === null ? null : $this->finder->match($below, $option, 1);
        } catch (NoMatch) {
            return null;
        }
    }

    /**
     * Whether an option under the group with exactly that label reads as selected. On
     * Android the words and the radio button are two nodes, and either can carry it.
     *
     * @param  list<Element>  $elements
     */
    private function radioSelected(array $elements, string $group, string $option): bool
    {
        foreach ($this->below($elements, $group) ?? [] as $element) {
            if ($element['label'] === $option && ($element['selected'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The elements below a label, or null when the label is not on screen.
     *
     * @param  list<Element>  $elements
     * @return list<Element>|null
     */
    private function below(array $elements, string $label): ?array
    {
        try {
            $top = $this->finder->match($elements, $label)['center'][1];
        } catch (NoMatch|AmbiguousMatch) {
            return null;
        }

        return array_values(array_filter(
            $elements,
            fn (array $element): bool => is_array($element['center'] ?? null) && $element['center'][1] > $top,
        ));
    }

    /**
     * Taps a tab in a `<native:tab-row>` or a tab bar, and waits until it is the active one.
     */
    public function changeTab(string $label): self
    {
        return $this->step('changeTab', $label, function () use ($label): void {
            $this->touch($this->locate($label, 'change to'));
            $this->until(
                fn (array $elements): bool => $this->finder->tabActive($elements, $label),
                "[{$label}] did not become the active tab.",
            );
        });
    }

    /**
     * Closes the sheet that is up. On iOS it drags the sheet down by its grabber and waits
     * until it has gone. Android presses Back, which closes a bottom sheet. With no iOS
     * sheet in the tree it presses Back on either platform, which goes back a screen.
     */
    public function dismissSheet(): self
    {
        return $this->step('dismissSheet', null, function (): void {
            $this->readRetrying();
            $grabber = $this->finder->sheetTop($this->screenElements);

            if ($grabber === null) {
                $this->presented();
                $this->sent(['back']);
                $this->driver->back();
                $this->settle();

                return;
            }

            [, $height] = $this->driver->viewport();
            $x = (float) $grabber['center'][0];
            $y = (float) $grabber['center'][1];
            $this->drag([$x, $y, $x, $height * 0.95], 0.4);
            $this->until(
                fn (): bool => ! $this->finder->hasSheet($this->screenElements),
                'The sheet did not close.',
            );
        });
    }

    /**
     * How many drags slide() makes to land on a value. Each one starts where the last one
     * let go, and measures how far the value moved per point to aim the next.
     */
    private const SLIDE_ATTEMPTS = 5;

    /**
     * Drags a slider's thumb to a value. Android reports the slider's range. iOS does not,
     * so pass the `min` and `max` the slider has: slide('Volume', 75, min: 0, max: 100).
     */
    public function slide(string $label, float $value, ?float $min = null, ?float $max = null): self
    {
        return $this->step('slide', "{$label}: {$value}", function () use ($label, $value, $min, $max): void {
            $slider = $this->locate($label, 'slide');
            $nth = $this->stepNth;
            [$low, $high] = $this->range($slider, $label, $min, $max);

            if ($value < $low || $value > $high) {
                throw new SimulatorException("[{$value}] is outside [{$label}]'s range of {$low} to {$high}.");
            }

            $frame = $slider['frame'] ?? null;
            $current = $this->number($slider['value'] ?? null);

            if (! is_array($frame) || $current === null) {
                throw new SimulatorException("[{$label}] reports no value or frame, so slide() cannot tell where its thumb is.");
            }

            [$left, , $width] = $frame;
            $inset = min($frame[3] / 2, $width * 0.1);
            $y = (float) $slider['center'][1];
            $close = ($high - $low) / 200;
            // A drag shorter than this can read as a tap, which leaves the thumb where it is:
            // a slider in SwiftUI stayed one step short of the value on a correction of 3pt.
            $shortest = $frame[3] * 0.6;
            $along = fn (float $to): float => max($left, min($left + $width, $to));

            // Where a drag lets go, and the value it leaves, is the one thing that holds on
            // both platforms: iOS's thumb follows the finger from where it was grabbed, and
            // Compose's jumps to where the finger first touches. Each drag adds a point, and
            // the line through the last two aims the next. Until there are two, the line is
            // the track's own: inset by half the thumb at each end.
            $track = new SliderLine($left + $inset, $low, ($width - 2 * $inset) / ($high - $low));
            $line = $track;
            $x = $along($line->at($current));
            $seeks = null;

            for ($drag = 1; $drag <= self::SLIDE_ATTEMPTS && abs($current - $value) > $close; $drag++) {
                $target = $along($line->at($value));

                // Compose's slider jumps to where it is touched, and a tap leaves exactly the
                // value there. A drag only moves the thumb once it has gone a touch slop, so it
                // stops short by an amount that depends on its direction: on an android-36
                // emulator, drags swung the value between 61 and 83. iOS's slider ignores a
                // tap on its track, which the first tap shows, and then it is dragged.
                if ($seeks !== false && abs($target - $x) > 1) {
                    $this->touch(['center' => [$target, $y]]);
                    $moved = $this->sliderValue($label, $nth) ?? $current;
                    $seeks ??= abs($moved - $current) > $close;

                    if ($seeks) {
                        $this->note('taps', $drag);
                        $x = $target;
                        $current = $moved;
                        $line = $line->through($x, $current);

                        continue;
                    }
                }

                if (abs($target - $x) < $shortest) {
                    // Back away first, so the drag onto the value is long enough to count.
                    $away = $along($target >= $x ? $x - 2 * $shortest : $x + 2 * $shortest);
                    $this->drag([$x, $y, $away, $y], 0.4);
                    $x = $away;
                    $current = $this->sliderValue($label, $nth) ?? $current;
                    $line = $line->through($x, $current);
                    $target = $along($line->at($value));
                }

                $before = $current;
                $this->drag([$x, $y, $target, $y], 0.5);
                $this->note('drags', $drag);
                $current = $this->sliderValue($label, $nth) ?? $current;

                // A drag that moved nothing did not start on the thumb. On iOS a drag that fell
                // short leaves the thumb short of where the finger let go: on CI's macOS runner
                // a drag to 75 left 67, and each drag after it started past the thumb and moved
                // nothing. So the next one starts where the thumb is drawn.
                if ($current === $before) {
                    $x = $along($track->at($current));
                    $line = $track->through($x, $current);

                    continue;
                }

                $x = $target;
                $line = $line->through($x, $current);
            }

            $this->until(
                fn (array $elements): bool => $this->canMatch($elements, $label)
                    && abs(($this->number($this->finder->match($elements, $label, $nth)['value'] ?? null) ?? INF) - $value) <= $close,
                "[{$label}] stopped at {$current}, not {$value}.",
            );
        });
    }

    private function sliderValue(string $label, ?int $nth): ?float
    {
        $elements = $this->until(fn (array $elements): bool => $this->canMatch($elements, $label), "Could not find [{$label}] after sliding it.");

        return $this->number($this->finder->match($elements, $label, $nth)['value'] ?? null);
    }

    /**
     * @param  Tappable  $slider
     * @return array{0: float, 1: float}
     */
    private function range(array $slider, string $label, ?float $min, ?float $max): array
    {
        $reported = $slider['range'] ?? null;
        $low = $min ?? (is_array($reported) ? $reported[0] : null);
        $high = $max ?? (is_array($reported) ? $reported[1] : null);

        if ($low === null || $high === null) {
            throw new SimulatorException("[{$label}] does not say its range. Pass the slider's min and max: slide('{$label}', 50, min: 0, max: 100).");
        }

        if ($high <= $low) {
            throw new SimulatorException("[{$label}]'s max [{$high}] is not above its min [{$low}].");
        }

        return [$low, $high];
    }

    private function number(mixed $value): ?float
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(str_replace(['%', ','], ['', '.'], $value));

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Adds an image or video to the device's photo library, for pickPhoto() to choose. It
     * stays in the library until --wipe.
     */
    public function addPhoto(string $path): self
    {
        return $this->step('addPhoto', $path, function () use ($path): void {
            $file = Photo::file($path);
            $this->sent(['addMedia', $file]);
            $this->driver->addMedia($file);
        });
    }

    /**
     * Makes sure the device has a face or finger enrolled. Call it before the screen that
     * offers Face ID or a fingerprint: an app checks before it shows the option.
     */
    public function enrollBiometrics(): self
    {
        return $this->biometric('enrollBiometrics', 'enroll');
    }

    /**
     * Answers the Face ID or fingerprint prompt on screen with a match.
     */
    public function matchBiometric(): self
    {
        return $this->biometric('matchBiometric', 'match');
    }

    /**
     * Answers the Face ID or fingerprint prompt on screen with a face or finger it does
     * not know.
     */
    public function failBiometric(): self
    {
        return $this->biometric('failBiometric', 'fail');
    }

    private function biometric(string $step, string $action): self
    {
        return $this->step($step, null, function () use ($action): void {
            $this->sent(['biometric', $action]);
            $this->driver->biometric($action);
        });
    }

    /**
     * Delivers a push notification to the app, as APNs would. iOS only.
     *
     * @param  array<string, mixed>  $data  custom keys beside `aps`, for the app to read
     */
    public function push(string $title, string $body = '', array $data = []): self
    {
        return $this->step('push', $title, function () use ($title, $body, $data): void {
            $this->sent(['push', $title]);
            $this->driver->push(PushNotification::payload($title, $body, $data));
        });
    }

    public function alert(string $label): self
    {
        return $this->step('alert', $label, function () use ($label): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->alertButton($elements, $label) !== null,
                "The alert did not offer [{$label}].",
            );
            /** @var Tappable $button until() waited for it */
            $button = $this->finder->alertButton($elements, $label);
            $this->touch($button);
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
            /** @var Tappable $button until() waited for it */
            $button = $this->finder->shareButton($elements, $target);
            $this->touch($button);
        });
    }

    /**
     * How many times pickPhoto() taps a photo. iOS 26's picker draws its grid before it
     * takes touches: a tap 1.5 seconds after the grid showed did nothing, and the same tap
     * 3 seconds after it picked the photo.
     */
    private const PICK_ATTEMPTS = 3;

    public function pickPhoto(): self
    {
        return $this->step('pickPhoto', null, function (): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->firstPhoto($elements) !== null,
                'The photo picker had no image.',
            );

            for ($attempt = 1; $attempt <= self::PICK_ATTEMPTS; $attempt++) {
                $photo = $this->finder->firstPhoto($elements);

                if ($photo === null) {
                    return;
                }

                $this->note('taps', $attempt);
                $this->tapQuickly($photo);

                // A picker that takes one photo closes. One that takes several asks to be
                // confirmed. Either says the tap was taken.
                $deadline = microtime(true) + min(2.0, $this->timeoutSeconds);

                do {
                    try {
                        $now = $this->read();
                    } catch (SimulatorException) {
                        $now = [];
                    }

                    // A read that failed or came back empty says nothing about the picker,
                    // so the next try works from the last one that did.
                    if ($now !== []) {
                        $elements = $now;
                        $confirm = $this->finder->photoConfirm($elements);

                        if ($confirm !== null) {
                            $this->touch($confirm);

                            return;
                        }

                        if ($this->finder->firstPhoto($elements) === null) {
                            return;
                        }
                    }

                    if ($this->timeoutSeconds > 0) {
                        usleep(200_000);
                    }
                } while (microtime(true) < $deadline);
            }

            $this->fail('The photo picker did not take a tap on its first photo, tapped '.self::PICK_ATTEMPTS.' times.', $elements);
        });
    }

    public function cancelPhoto(): self
    {
        return $this->step('cancelPhoto', null, function (): void {
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->photoButton($elements, 'Cancel') !== null,
                'The photo picker had no [Cancel] button.',
            );
            /** @var Tappable $cancel until() waited for it */
            $cancel = $this->finder->photoButton($elements, 'Cancel');
            $this->touch($cancel);
        });
    }

    public function assertValue(string $label, string $value): self
    {
        return $this->step('assertValue', $label, function () use ($label, $value): void {
            $nth = $this->index();
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->hasValue($elements, $label, $value, $nth),
                "[{$label}] did not have value [{$value}].",
            );

            Assert::assertTrue($this->finder->hasValue($elements, $label, $value, $nth));
        });
    }

    public function assertEnabled(string $label): self
    {
        return $this->step('assertEnabled', $label, function () use ($label): void {
            $nth = $this->index();
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isEnabled($elements, $label, true, $nth),
                "[{$label}] was disabled.",
            );

            Assert::assertTrue($this->finder->isEnabled($elements, $label, true, $nth));
        });
    }

    public function assertDisabled(string $label): self
    {
        return $this->step('assertDisabled', $label, function () use ($label): void {
            $nth = $this->index();
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isEnabled($elements, $label, false, $nth),
                "[{$label}] was enabled.",
            );

            Assert::assertTrue($this->finder->isEnabled($elements, $label, false, $nth));
        });
    }

    public function assertChecked(string $label): self
    {
        return $this->step('assertChecked', $label, function () use ($label): void {
            $nth = $this->index();
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isChecked($elements, $label, true, $nth),
                "[{$label}] was unchecked.",
            );

            Assert::assertTrue($this->finder->isChecked($elements, $label, true, $nth));
        });
    }

    /**
     * The negative of assertChecked(), the same way assertNotSelected() is the negative of
     * assertSelected().
     */
    public function assertNotChecked(string $label): self
    {
        return $this->step('assertNotChecked', $label, function () use ($label): void {
            $nth = $this->index();
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isChecked($elements, $label, false, $nth),
                "[{$label}] was checked.",
            );

            Assert::assertTrue($this->finder->isChecked($elements, $label, false, $nth));
        });
    }

    /**
     * A `<native:chip>`'s own on/off state, which assertChecked() cannot answer — chips
     * report `.isSelected`, not the Switch-only `checked` value assertChecked() reads.
     */
    public function assertSelected(string $label): self
    {
        return $this->step('assertSelected', $label, function () use ($label): void {
            $nth = $this->index();
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isSelected($elements, $label, true, $nth),
                "[{$label}] was not selected.",
            );

            Assert::assertTrue($this->finder->isSelected($elements, $label, true, $nth));
        });
    }

    /**
     * The negative of assertSelected() — asserts a chip has NOT been toggled on, the same
     * way assertDisabled() is the negative of assertEnabled().
     */
    public function assertNotSelected(string $label): self
    {
        return $this->step('assertNotSelected', $label, function () use ($label): void {
            $nth = $this->index();
            $elements = $this->until(
                fn (array $elements): bool => $this->finder->isSelected($elements, $label, false, $nth),
                "[{$label}] was selected.",
            );

            Assert::assertTrue($this->finder->isSelected($elements, $label, false, $nth));
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
            $texts = [$text, ...array_values($others)];

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
     * Compares the screen with a baseline saved from an earlier run. The first run saves
     * the baseline and passes; --update-screenshots saves it again. The status bar is fixed
     * at 9:41 first, so the clock does not count as a change. A screen still moving gets
     * until the timeout to match.
     *
     * $threshold is the share of pixels that may differ: 0.001 is a tenth of a percent.
     */
    public function assertScreenshotMatches(?string $name = null, float $threshold = 0.001): self
    {
        if (! Run::inside()) {
            throw new SimulatorException('assertScreenshotMatches() only works inside a mobile() suite.');
        }

        return $this->step('assertScreenshotMatches', $name, function () use ($name, $threshold): void {
            $device = Run::device();
            DeviceState::change($this->driver, $device->key(), 'statusBar', true);
            $baseline = Screenshots::baseline($device, $name);
            $this->settle();
            $elements = $this->readRetrying();

            if (! is_file($baseline) || Arguments::wantsScreenshotUpdate()) {
                if (! is_file($baseline) && in_array(strtolower((string) getenv('CI')), ['1', 'true'], true)) {
                    throw new AssertionFailedError("There is no baseline at [{$baseline}]. Run the test outside CI once to save it, and commit it.");
                }

                if (! is_dir(dirname($baseline))) {
                    mkdir(dirname($baseline), 0777, true);
                }

                $this->driver->screenshot($baseline);
                $this->note('baseline', 'saved');

                return;
            }

            $actual = sys_get_temp_dir().'/pest-simulator-screenshot-'.uniqid('', true).'.png';
            $deadline = microtime(true) + $this->timeoutSeconds;

            try {
                do {
                    $this->driver->screenshot($actual);
                    [$share, $marked] = Screenshots::compare($baseline, $actual);
                    $this->note('different', round($share, 5));

                    if ($share <= $threshold) {
                        return;
                    }

                    if ($this->timeoutSeconds > 0) {
                        usleep(500_000);
                    }
                } while ($this->timeoutSeconds > 0 && microtime(true) < $deadline);

                $root = $this->failureDirectory !== '' ? $this->failureDirectory : FailureCapture::directory();

                if (! is_dir($root)) {
                    mkdir($root, 0777, true);
                }

                $saved = [];

                if ($marked !== null && imagepng($marked, $root.'/screenshot-diff.png')) {
                    $saved[] = $root.'/screenshot-diff.png';
                }

                $percent = fn (float $share): string => rtrim(rtrim(number_format($share * 100, 2), '0'), '.').'%';
                $message = $marked === null
                    ? "The screenshot is not the size of the baseline [{$baseline}]."
                    : 'The screen differs from the baseline in '.$percent($share).' of its pixels, more than '.$percent($threshold).". Baseline: [{$baseline}].";

                $this->fail($message, $elements, null, $root, $saved);
            } finally {
                @unlink($actual);
            }
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
     * `light` or `dark` until the test ends. The next test starts from the device's own.
     */
    public function appearance(string $mode): self
    {
        return $this->deviceSetting('appearance', $mode, fn (): string => DeviceState::appearance($mode));
    }

    /**
     * One of DeviceState's text sizes, such as `large`, until the test ends.
     */
    public function textSize(string $size): self
    {
        return $this->deviceSetting('textSize', $size, fn (): string => DeviceState::size($size));
    }

    public function location(float $latitude, float $longitude): self
    {
        return $this->deviceSetting('location', "{$latitude}, {$longitude}", fn (): array => DeviceState::coordinates($latitude, $longitude));
    }

    /**
     * A status bar that reads 9:41 with full signal and battery, for screenshots that match.
     */
    public function fixStatusBar(): self
    {
        return $this->deviceSetting('fixStatusBar', null, fn (): bool => true, 'statusBar');
    }

    /**
     * @param  Closure(): mixed  $value  the setting's value, checked inside the step
     */
    private function deviceSetting(string $action, ?string $target, Closure $value, ?string $setting = null): self
    {
        if (! Run::inside()) {
            throw new SimulatorException("{$action}() only works inside a mobile() suite.");
        }

        return $this->step($action, $target, function () use ($action, $value, $setting): void {
            DeviceState::change($this->driver, Run::device()->key(), $setting ?? $action, $value());
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

        $this->stepNth = $this->nth;
        $this->nth = null;
        $this->nthUsed = false;
        $this->step = ['action' => $action, 'target' => $target, 'started' => microtime(true), 'reads' => 0, 'failedReads' => 0];

        if ($this->stepNth !== null) {
            $this->step['nth'] = $this->stepNth;
        }

        if ($this->scopes !== []) {
            $this->step['within'] = $this->scopes;
        }

        try {
            $body();

            if ($this->stepNth !== null && ! $this->nthUsed) {
                throw new SimulatorException("nth() picks one of the controls a label matches. {$action}() does not look one up, so there is nothing to count.");
            }
        } catch (Throwable $exception) {
            $this->endStep($exception instanceof AssertionFailedError ? 'failed' : 'error', $exception->getMessage());

            throw $exception;
        } finally {
            $this->stepNth = null;
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

    /**
     * nth() for the call under way, marked as used.
     */
    private function index(): ?int
    {
        if ($this->stepNth !== null) {
            $this->nthUsed = true;
        }

        return $this->stepNth;
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

    private function append(string $key, mixed $value): void
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
        $this->append('sent', $action);
    }

    /**
     * Tap a control's center, once any sheet has finished sliding in (see presented()).
     *
     * @param  array{center: array{0: float|int, 1: float|int}}  $element
     */
    private function touch(array $element): void
    {
        $this->idle();
        $this->presented();
        $x = (float) $element['center'][0];
        $y = (float) $element['center'][1];
        $this->sent(['tap', $x, $y]);
        $this->driver->tap($x, $y);
    }

    /**
     * A tap with almost no hold. iOS 26's photo picker takes touch()'s usual hold on a photo
     * as the start of a long press, which previews the photo instead of picking it: with a
     * 50ms hold the same photo was picked.
     *
     * @param  array{center: array{0: float|int, 1: float|int}}  $element
     */
    private function tapQuickly(array $element): void
    {
        $this->presented();
        $x = (float) $element['center'][0];
        $y = (float) $element['center'][1];
        $this->sent(['tap', $x, $y]);
        $this->driver->press($x, $y, 0.05);
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
     * @return list<Element>
     */
    private function readRetrying(): array
    {
        $deadline = $this->deadline();

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
     * @param  callable(list<Element>): bool  $predicate
     * @param  string|Closure(list<Element>): string  $failure
     * @return list<Element>
     */
    private function until(callable $predicate, string|Closure $failure, ?float $deadline = null): array
    {
        $deadline ??= $this->deadline();
        $launching = $this->launching > microtime(true);
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

            // The app is still answering the last thing it was sent: what is on screen now
            // may change, so it is not the answer yet.
            if ($this->busy() && microtime(true) < $deadline) {
                usleep(50_000);

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

        if ($this->scopes !== []) {
            $message .= $this->scopeMissing !== null
                ? "\n\nCould not find [{$this->scopeMissing}] to look within."
                : "\n\nLooked only within [".implode('] then [', $this->scopes).'].';
        }

        if ($this->busy()) {
            $message .= "\n\nThe app was still busy: PHP had not taken {$this->pending} ".($this->pending === 1 ? 'event' : 'events').' it was sent.';
        }

        if ($launching) {
            $message .= "\n\nThis was the app's first launch since it was installed, when it unpacks its Laravel code, so the step waited up to ".self::FIRST_LAUNCH_SECONDS.' seconds from the launch.';
        }

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
     * @param  list<Element>  $elements
     * @param  list<string>  $saved  files the failing step wrote into $root itself
     */
    private function fail(string $failure, array $elements, ?SimulatorException $unread = null, ?string $root = null, array $saved = []): never
    {
        $this->endStep('failed', $failure);

        throw new AssertionFailedError($failure."\n\n".$this->listing($this->scopes === [] ? $elements : $this->screenElements).$this->captureFailure($unread, $root, $saved));
    }

    /**
     * The controls on screen, as a failure message and dump() list them.
     *
     * @param  list<Element>  $elements
     */
    private function listing(array $elements): string
    {
        $webview = $this->finder->hasWebView($elements)
            ? "\n\nA WebView is on screen. Blade and Livewire inside <webview> are outside the native accessibility tree."
            : '';

        return $this->finder->describe($elements).$webview;
    }

    /**
     * @return list<Element>
     */
    private function read(): array
    {
        $elements = $this->describe();
        $this->notePresenting($elements);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $button = $this->finder->openDialogButton($elements, $this->driver->openPrompts(...));

            if ($button === null) {
                break;
            }

            $this->touch($button);

            if ($this->timeoutSeconds > 0) {
                usleep(800_000);
            }

            $elements = $this->describe();
            $this->notePresenting($elements);
        }

        $this->screenElements = $elements;

        return $this->scoped($elements);
    }

    /**
     * Notes when a sheet or alert first showed up, for presented(). read() notes it before it
     * answers the "Open in" prompt, so that tap waits out the prompt's slide-in too.
     *
     * @param  list<Element>  $elements
     */
    private function notePresenting(array $elements): void
    {
        $presenting = $this->finder->hasSheet($elements) || $this->finder->hasAlert($elements);
        $this->sheetSeen = $presenting ? ($this->sheetSeen ?? microtime(true)) : null;
    }

    /**
     * The elements inside every within() control, found again in this read. None when one
     * of them is not on the screen, so until() keeps waiting for it.
     *
     * @param  list<Element>  $elements
     * @return list<Element>
     */
    private function scoped(array $elements): array
    {
        $this->scopeMissing = null;

        foreach ($this->scopes as $scope) {
            try {
                $frame = $this->finder->match($elements, $scope)['frame'] ?? null;
            } catch (NoMatch) {
                $frame = null;
            } catch (AmbiguousMatch) {
                // Fails now: thrown as a SimulatorException, until() would take it for a
                // read that failed and retry it until the timeout.
                $this->fail("within('{$scope}') matches more than one control. Give the one to look inside an accessibility id, and name that.", $elements);
            }

            if (! is_array($frame)) {
                $this->scopeMissing = $scope;

                return [];
            }

            [$x, $y, $width, $height] = $frame;
            $elements = array_values(array_filter(
                $elements,
                fn (array $element): bool => is_array($element['center'] ?? null)
                    && $element['center'][0] >= $x && $element['center'][0] <= $x + $width
                    && $element['center'][1] >= $y && $element['center'][1] <= $y + $height,
            ));
        }

        return $elements;
    }

    /**
     * @return list<Element>
     */
    private function describe(): array
    {
        $this->tally('reads');

        try {
            [$elements, $this->pending] = IdleMarker::split($this->driver->describe());
        } catch (SimulatorException $exception) {
            $this->tally('failedReads');

            throw $exception;
        }

        if ($this->busy()) {
            $this->tally('busyReads');
        }

        return $elements;
    }

    /**
     * When a step that starts now stops waiting: after the timeout, or at the end of the
     * app's first launch after an install, whichever is later.
     */
    private function deadline(): float
    {
        return max(microtime(true) + $this->timeoutSeconds, $this->launching);
    }

    /**
     * Whether the last read's marker said PHP still had events to take.
     */
    private function busy(): bool
    {
        return $this->pending !== null && $this->pending > 0;
    }

    /**
     * Reads until the app's marker says PHP has taken every event it was sent, or the time
     * runs out. Returns at once for an app that shows no marker.
     */
    private function idle(?float $deadline = null): void
    {
        if (! $this->busy() || $this->timeoutSeconds <= 0 || $this->idling) {
            return;
        }

        $deadline ??= microtime(true) + $this->timeoutSeconds;
        $started = microtime(true);
        $this->idling = true;

        try {
            do {
                usleep(50_000);

                try {
                    $this->read();
                } catch (SimulatorException) {
                }
            } while ($this->pending > 0 && microtime(true) < $deadline);
        } finally {
            $this->idling = false;
        }

        $this->tally('idleWait', microtime(true) - $started);
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
     * An alert drops touches the same way while it fades in: a tap on an alert's button sent
     * on the first read that had the alert did nothing, and the alert stayed up.
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
     * @param  list<Element>  $elements
     */
    private function canMatch(array $elements, string $label): bool
    {
        try {
            $this->finder->match($elements, $label, $this->stepNth);

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
     * @return Tappable
     */
    private function locate(string $label, string $action, ?string $search = null, ?array $row = null): array
    {
        $deadline = $this->deadline();
        $scrolls = 0;
        $nth = $this->index();

        while (true) {
            $elements = $this->until(
                fn (array $visible): bool => $search !== null || $this->canMatch($visible, $label),
                fn (array $visible): string => $this->notFound($label, $action, $visible, $nth),
                $deadline,
            );

            try {
                $match = $this->finder->match($elements, $label, $nth);
            } catch (NoMatch) {
                $match = null;
            } catch (AmbiguousMatch $ambiguous) {
                $this->fail($ambiguous->getMessage(), $elements);
            }

            if ($match === null) {
                // until() lets a read without the label through only when $search says which way to scroll.
                /** @var string $search */
                if (($search === 'left' || $search === 'right') && $row === null) {
                    $row = $this->onlyCarousel($label, $search, $elements);
                }

                [$width, $height] = $this->driver->viewport();
                $scroll = [null, $row === null
                    ? Gesture::scroll($search, $width, $height)
                    : Gesture::sideways($search, ...$row)];
            } else {
                $scroll = $this->toward($match);
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

            $this->append('scrolls', match ($where) {
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
     * Why locate() found nothing: with nth(), how many the label did match.
     *
     * @param  list<Element>  $elements
     */
    private function notFound(string $label, string $action, array $elements, ?int $nth): string
    {
        $message = "Could not find [{$label}] to {$action}.";

        if ($nth === null) {
            return $message;
        }

        try {
            $this->finder->match($elements, $label, $nth);
        } catch (NoMatch $missing) {
            if ($missing->getMessage() !== (new NoMatch($label))->getMessage()) {
                return $message.' '.$missing->getMessage();
            }
        }

        return $message;
    }

    /**
     * Where a control is off screen, and the drag that brings it toward the middle, or null
     * when it is already on screen. A nav bar or tab bar's own controls never move, so they
     * are never scrolled toward. Nor is a key on the software keyboard: on the glass it is
     * below the part of the screen a tap is trusted to, and no scroll moves a parked one.
     *
     * Up and down come first. A card past the edge of the carousel it sits in is then
     * dragged sideways inside that carousel. A control past the edge of the screen in
     * nothing that scrolls sideways gets no drag (a null gesture): a sideways drag on a
     * list row can open its swipe actions.
     *
     * @param  Tappable  $match
     * @return array{0: string, 1: array{0: float, 1: float, 2: float, 3: float}|null}|null
     */
    private function toward(array $match): ?array
    {
        if (($match['chrome'] ?? null) !== null || ($match['role'] ?? null) === 'Key') {
            return null;
        }

        [$width, $height] = $this->driver->viewport();
        [$top, $bottom] = $this->visibleBand($this->screenElements, $height);
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
     * @param  list<Element>  $elements
     * @return array{0: float, 1: float, 2: float}
     */
    private function onlyCarousel(string $label, string $direction, array $elements): array
    {
        [, $height] = $this->driver->viewport();
        [$top, $bottom] = $this->visibleBand($this->screenElements, $height);
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
     * An iOS software keyboard covers the screen from its top row of keys down, with its
     * suggestions bar above them. It shows when no hardware keyboard is connected, which is
     * how a Simulator booted without Simulator.app runs: on the sample app's form, a tap on
     * Save under the keyboard typed a "w" into the field instead. With a hardware keyboard
     * connected, the keyboard stays in the tree, parked with its keys below the display,
     * and covers nothing.
     *
     * @param  list<Element>  $elements
     * @return array{0: float, 1: float}
     */
    private function visibleBand(array $elements, float $height): array
    {
        $inset = $height * 0.03;
        $top = 0.0;
        $bottom = $height - $this->driver->homeIndicator();
        $keys = array_filter(
            $elements,
            fn (array $element): bool => ($element['role'] ?? null) === 'Key' && is_array($element['center'] ?? null) && (float) $element['center'][1] < $height,
        );

        if ($keys !== []) {
            // The top row's centers are half a key below the keyboard's edge, and the
            // suggestions bar sits above that edge: about 8% of the screen in all.
            $bottom = min($bottom, min(array_map(fn (array $key): float => (float) $key['center'][1], $keys)) - $height * 0.08);
        }

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
     * @param  ?string  $root  where to save, when the failing step already chose it
     * @param  list<string>  $saved  files the failing step wrote into $root itself
     */
    private function captureFailure(?SimulatorException $unread = null, ?string $root = null, array $saved = []): string
    {
        $root ??= $this->failureDirectory !== '' ? $this->failureDirectory : FailureCapture::directory();

        return FailureCapture::listing($root, [...FailureCapture::save($this->driver, $root, $unread), ...$saved]);
    }
}
