<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

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

    public function press(string $label): self
    {
        return $this->tap($label);
    }

    public function type(string $label, string $text): self
    {
        $elements = $this->until(
            fn (array $visible): bool => $this->canMatch($visible, $label),
            "Could not find [{$label}] to type into.",
        );

        $match = $this->finder->match($elements, $label);
        $this->driver->tap((float) $match['center'][0], (float) $match['center'][1]);
        $this->driver->clear();

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
        $this->driver->clear();

        return $this;
    }

    public function scroll(string $direction = 'down'): self
    {
        $this->read();
        [$width, $height] = $this->driver->viewport();
        [$x1, $y1, $x2, $y2] = Gesture::scroll($direction, $width, $height);
        $this->driver->swipe($x1, $y1, $x2, $y2);

        return $this;
    }

    public function swipe(string $direction, ?string $from = null): self
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
            $this->read();
        }

        [$width, $height] = $this->driver->viewport();
        [$x1, $y1, $x2, $y2] = Gesture::swipe($direction, $width, $height, $originX, $originY);
        $this->driver->swipe($x1, $y1, $x2, $y2);

        return $this;
    }

    public function goBack(): self
    {
        $this->read();
        $this->driver->back();

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

    public function assertSee(string $text): self
    {
        $elements = $this->until(
            fn (array $elements): bool => $this->finder->sees($elements, $text),
            "Did not see [{$text}].",
        );

        Assert::assertTrue($this->finder->sees($elements, $text));

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

            if ($predicate($last)) {
                return $last;
            }

            if ($this->timeoutSeconds <= 0) {
                break;
            }

            usleep(400_000);
        } while (microtime(true) < $deadline);

        $artifact = $this->captureFailure();
        $webview = $this->finder->hasWebView($last)
            ? "\n\nA WebView is on screen. Blade and Livewire inside <webview> are outside the native accessibility tree."
            : '';

        throw new AssertionFailedError($failure."\n\n".$this->finder->describe($last).$webview.$artifact);
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

        try {
            $this->driver->describe($root.'/tree.json');
        } catch (SimulatorException) {
        }

        try {
            $this->driver->screenshot($root.'/screen.png');
        } catch (SimulatorException) {
        }

        return "\n\nSaved {$root}";
    }
}
