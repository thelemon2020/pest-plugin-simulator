<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class DoctorResult
{
    /**
     * @param  list<array{label: string, ok: bool, required: bool, detail: string}>  $checks
     */
    public function __construct(private readonly array $checks) {}

    public function successful(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['required'] && ! $check['ok']) {
                return false;
            }
        }

        return ($this->ready('Xcode simctl') && $this->ready('idb_companion'))
            || ($this->ready('Android SDK') && $this->ready('adb') && $this->ready('emulator'));
    }

    public function render(): string
    {
        $lines = ['Simulator doctor', ''];

        foreach ($this->checks as $check) {
            $lines[] = sprintf('  %-22s %-13s %s', $check['label'], $this->status($check), $check['detail']);
        }

        $lines[] = '';
        $lines[] = ($this->ready('Xcode simctl') && $this->ready('idb_companion'))
            ? 'iOS Simulator tests can run on this machine.'
            : Doctor::unavailable('ios');
        $lines[] = ($this->ready('Android SDK') && $this->ready('adb') && $this->ready('emulator'))
            ? 'Android Emulator tests can run on this machine.'
            : Doctor::unavailable('android');
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array{label: string, ok: bool, required: bool, detail: string}  $check
     */
    private function status(array $check): string
    {
        return match (true) {
            ! $check['ok'] && $check['required'] => 'missing',
            ! $check['ok'] => 'unavailable',
            $check['detail'] === 'unset', $check['detail'] === 'optional' => 'unset',
            default => 'ok',
        };
    }

    private function ready(string $label): bool
    {
        foreach ($this->checks as $check) {
            if ($check['label'] === $label) {
                return $check['ok'];
            }
        }

        return false;
    }
}
