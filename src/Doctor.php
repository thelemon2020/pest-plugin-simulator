<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Doctor
{
    public function __construct(private readonly Machine $machine = new LocalMachine) {}

    public static function check(): DoctorResult
    {
        return (new self)->run(Configuration::resolve());
    }

    /**
     * @return list<'ios'|'android'>
     */
    public function platforms(): array
    {
        $platforms = [];

        if ($this->iosReady()) {
            $platforms[] = 'ios';
        }

        if ($this->androidReady()) {
            $platforms[] = 'android';
        }

        return $platforms;
    }

    public static function unavailable(string $platform): string
    {
        return match ($platform) {
            'ios' => 'iOS Simulator tests need Xcode and idb_companion (`brew install idb-companion`).',
            'android' => 'Android Emulator tests need the Android SDK, adb, and the emulator package.',
            default => throw new SimulatorException("Unknown platform [{$platform}]."),
        };
    }

    public function run(Configuration $configuration): DoctorResult
    {
        $xcrun = $this->machine->xcrun();
        $companion = $this->machine->companion();
        $sdk = $this->machine->androidSdk();
        $adb = $this->machine->adb();
        $emulator = $this->machine->emulator();
        $iosReady = $this->iosReady();
        $androidReady = $this->androidReady();
        $scheme = $configuration->deeplinkScheme();
        $host = $configuration->deeplinkHost();

        return new DoctorResult([
            $this->tool('Xcode simctl', $xcrun, 'Install Xcode and the command line tools.', ! $androidReady),
            $this->companionTool($companion, ! $androidReady),
            $this->tool('Android SDK', $sdk, 'Set ANDROID_HOME to the Android SDK.', ! $iosReady),
            $this->tool('adb', $adb, 'Install Android platform-tools.', ! $iosReady),
            $this->tool('emulator', $emulator, 'Install the Android Emulator package.', ! $iosReady),
            [
                'label' => 'Deep link scheme',
                'ok' => $scheme !== null || $host !== null,
                'required' => $scheme === null && $host === null,
                'detail' => $scheme ?? ($host !== null ? 'unset' : 'Set NATIVEPHP_DEEPLINK_SCHEME or NATIVEPHP_DEEPLINK_HOST.'),
            ],
            [
                'label' => 'Deep link host',
                'ok' => true,
                'required' => false,
                'detail' => $host ?? 'optional',
            ],
            [
                'label' => 'App id',
                'ok' => $configuration->appId() !== null,
                'required' => true,
                'detail' => $configuration->appId() ?? 'Set NATIVEPHP_APP_ID.',
            ],
        ]);
    }

    private function iosReady(): bool
    {
        return $this->machine->xcrun() !== null && $this->machine->companion() !== null;
    }

    private function androidReady(): bool
    {
        return $this->machine->androidSdk() !== null
            && $this->machine->adb() !== null
            && $this->machine->emulator() !== null;
    }

    /**
     * Not `required: true` on an old build — AX reads still work, and only AXBRIDGE reads
     * (native TabView children among them; see Hid::accessibilityInfo()) hang on it — but
     * an old build is worth surfacing here rather than as a 60-second hang mid-suite.
     *
     * @return array{label: string, ok: bool, required: bool, detail: string}
     */
    private function companionTool(?string $path, bool $required): array
    {
        if ($path === null) {
            return $this->tool('idb_companion', null, 'Install it with `brew install idb-companion`.', $required);
        }

        $supportsAxBridge = Companion::supportsAxBridge($path);

        $detail = match ($supportsAxBridge) {
            true => $path,
            false => $path.' — built '.Companion::buildDate($path).", too old for AXBRIDGE reads (needs 1.6.3+, 'brew upgrade idb-companion')",
            null => $path.' (build date unknown)',
        };

        return [
            'label' => 'idb_companion',
            'ok' => true,
            'required' => false,
            'detail' => $detail,
        ];
    }

    /**
     * @return array{label: string, ok: bool, required: bool, detail: string}
     */
    private function tool(string $label, ?string $path, string $hint, bool $required): array
    {
        return [
            'label' => $label,
            'ok' => $path !== null,
            'required' => $required && $path === null,
            'detail' => $path ?? $hint,
        ];
    }
}
