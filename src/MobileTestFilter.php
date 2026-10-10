<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Pest\Contracts\TestCaseMethodFilter;
use Pest\Factories\TestCaseMethodFactory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Group;

final class MobileTestFilter implements TestCaseMethodFilter
{
    public function accept(TestCaseMethodFactory $method): bool
    {
        if (! SuiteRegistration::active()) {
            return ! ParallelLanes::onlyMobileTests();
        }

        $devices = DevicePlan::filter(SuiteRegistration::devices(), $this->platforms($method));

        if ($devices === []) {
            if (Arguments::excludedByPin()) {
                return false;
            }

            $this->skip($method, 'This test is limited to a platform the mobile suite does not run.');

            return true;
        }

        $runnable = Platforms::runnable();
        $matched = $devices;
        $devices = array_values(array_filter(
            $devices,
            fn (Device $device): bool => in_array($device->platform, $runnable, true),
        ));

        if ($devices === []) {
            $this->skip($method, $this->unavailable($matched));

            return true;
        }

        $original = $method->closure;
        array_unshift($method->datasets, array_map(
            fn (Device $device): array => [$device->key()],
            $devices,
        ));

        $method->closure = function (string $key, mixed ...$args) use ($original) {
            $device = Device::fromKey($key);
            Run::useDevice($device);
            Trace::begin();
            VerboseLog::note('test '.Trace::test()." on {$device->platform} {$device->name}");
            TestDatabase::begin();
            Permissions::beginTest();

            if (Configuration::resolve()->recordFailures()) {
                Recording::everyTest();
            }

            try {
                return $original instanceof \Closure ? $original->call($this, ...$args) : null;
            } finally {
                try {
                    Recording::finish();
                } finally {
                    Permissions::forgetRequest();
                    TestDatabase::end();
                    Trace::reset();
                    Run::clear();
                }
            }
        };

        return true;
    }

    private function skip(TestCaseMethodFactory $method, string $reason): void
    {
        $method->closure = function () use ($reason): void {
            Assert::markTestSkipped($reason);
        };
    }

    /**
     * @param  list<Device>  $devices
     */
    private function unavailable(array $devices): string
    {
        $platforms = [];

        foreach ($devices as $device) {
            $platforms[$device->platform] = true;
        }

        $messages = [];

        if (isset($platforms['ios'])) {
            $messages[] = Doctor::unavailable('ios');
        }

        if (isset($platforms['android'])) {
            $messages[] = Doctor::unavailable('android');
        }

        return implode(' ', $messages);
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
