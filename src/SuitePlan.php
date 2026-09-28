<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class SuitePlan
{
    /**
     * @param  list<string>|null  $ios
     * @param  list<string>|null  $android
     * @param  list<'ios'|'android'>  $runnable
     * @return list<Device>
     */
    public static function devices(
        bool $iosTouched,
        ?array $ios,
        bool $androidTouched,
        ?array $android,
        array $runnable,
        DeviceCatalog $catalog,
    ): array {
        if (! $iosTouched && ! $androidTouched) {
            $iosTouched = true;
            $androidTouched = true;
        }

        $latest = '';
        $avd = '';

        if ($iosTouched && $ios === null && in_array('ios', $runnable, true)) {
            try {
                $latest = $catalog->latestIphone();
            } catch (SimulatorException) {
                Platforms::block('ios');
                $runnable = array_values(array_filter(
                    $runnable,
                    fn (string $platform): bool => $platform !== 'ios',
                ));
            }
        }

        if ($androidTouched && $android === null && in_array('android', $runnable, true)) {
            try {
                $avd = $catalog->defaultAvd();
            } catch (SimulatorException) {
                Platforms::block('android');
                $runnable = array_values(array_filter(
                    $runnable,
                    fn (string $platform): bool => $platform !== 'android',
                ));
            }
        }

        if ($iosTouched && $ios === null && ! in_array('ios', $runnable, true)) {
            $ios = ['iPhone'];
        }

        if ($androidTouched && $android === null && ! in_array('android', $runnable, true)) {
            $android = ['Android'];
        }

        return DevicePlan::resolve($iosTouched, $ios, $androidTouched, $android, $latest, $avd);
    }
}
