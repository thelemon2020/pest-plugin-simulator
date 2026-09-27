<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

final class Doctor
{
    public function __construct(private readonly Machine $machine = new LocalMachine) {}

    public static function check(): DoctorResult
    {
        return (new self)->run(Configuration::resolve());
    }

    public function run(Configuration $configuration): DoctorResult
    {
        $xcrun = $this->machine->xcrun();
        $companion = $this->machine->companion();
        $sdk = $this->machine->androidSdk();
        $adb = $this->machine->adb();
        $emulator = $this->machine->emulator();
        $iosReady = $xcrun !== null && $companion !== null;
        $androidReady = $sdk !== null && $adb !== null && $emulator !== null;
        $scheme = $configuration->deeplinkScheme();
        $host = $configuration->deeplinkHost();

        return new DoctorResult([
            $this->tool('Xcode simctl', $xcrun, 'Install Xcode and the command line tools.', ! $androidReady),
            $this->tool('idb_companion', $companion, 'Install it with `brew install idb-companion`.', ! $androidReady),
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
