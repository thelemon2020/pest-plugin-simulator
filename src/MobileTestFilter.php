<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Pest\Contracts\TestCaseMethodFilter;
use Pest\Factories\TestCaseMethodFactory;
use PHPUnit\Framework\Attributes\Group;

final class MobileTestFilter implements TestCaseMethodFilter
{
    public function accept(TestCaseMethodFactory $method): bool
    {
        if (! SuiteRegistration::active()) {
            return true;
        }

        $devices = DevicePlan::filter(SuiteRegistration::devices(), $this->platforms($method));
        $original = $method->closure;

        if ($devices === []) {
            $method->datasets[] = ['missing'];
            $method->closure = function (): void {
                throw new Exceptions\SimulatorException('This test is limited to a platform the mobile suite does not run.');
            };

            return true;
        }

        $method->datasets[] = array_map(
            fn (Device $device): array => [$device->key()],
            $devices,
        );

        $method->closure = function (string $key) use ($original) {
            Run::useDevice(Device::fromKey($key));

            try {
                return $original instanceof \Closure ? $original->call($this) : null;
            } finally {
                Run::clear();
            }
        };

        return true;
    }

    /**
     * @return list<string>
     */
    private function platforms(TestCaseMethodFactory $method): array
    {
        $platforms = [];

        foreach ($method->attributes as $attribute) {
            if ($attribute->name !== Group::class) {
                continue;
            }

            foreach ($attribute->arguments as $group) {
                if ($group === 'ios' || $group === 'android') {
                    $platforms[] = $group;
                }
            }
        }

        return $platforms;
    }
}
