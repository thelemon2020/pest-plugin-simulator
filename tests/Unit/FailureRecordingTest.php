<?php

declare(strict_types=1);

use NativePhp\Simulator\Arguments;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Plugin;
use NativePhp\Simulator\Recording;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\Subscribers\TestFinished;
use NativePhp\Simulator\Subscribers\TestPassed;
use NativePhp\Simulator\TestDatabase;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\Passed;
use Tests\Support\FakeDriver;

beforeEach(function () {
    $this->driver = new FakeDriver([[['label' => 'Save', 'role' => 'Button', 'id' => null, 'center' => [100, 200]]]]);
    $this->database = (string) tempnam(sys_get_temp_dir(), 'simulator-');
    // Clips go under the working directory. A directory of its own keeps them apart from
    // RecordingTest's, which can run at the same time in another parallel worker.
    $directory = sys_get_temp_dir().'/simulator-failure-recording-'.uniqid('', true);
    mkdir($directory);
    $this->directory = (string) realpath($directory);
    $this->cwd = getcwd();
    chdir($this->directory);
    // Before the test starts: that is when a mobile() test decides to record.
    Configuration::configure(['scheme' => 'myapp', 'record_failures' => true]);
    Sessions::fake($this->driver);
    TestDatabase::fake(fn (): string => $this->database);
});

afterEach(function () {
    Sessions::fake(null);
    TestDatabase::fake(null);
    Configuration::reset();
    Arguments::reset();

    if (is_file($this->database)) {
        unlink($this->database);
    }

    chdir($this->cwd);

    // A clip that waits on PHPUnit's verdict is still here. The last test clears it away.
    if ((glob($this->directory.'/simulator-recordings/*') ?: []) === []) {
        removeRecordingDirectory($this->directory);
    }
});

function removeRecordingDirectory(string $path): void
{
    foreach (glob($path.'/*') ?: [] as $entry) {
        is_dir($entry) ? removeRecordingDirectory($entry) : unlink($entry);
    }

    rmdir($path);
}

/**
 * What simctl or adb would have written by the time the clip stops.
 */
function filmed(FakeDriver $driver): string
{
    $path = (string) $driver->recording;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, 'mp4');

    return $path;
}

function automaticClip(FakeDriver $driver): string
{
    Recording::everyTest();
    Recording::begin($driver, new Device('ios', 'iPhone', true));

    return filmed($driver);
}

it('deletes the clip of a test that passed', function () {
    $path = automaticClip($this->driver);
    Recording::finish($this->driver);

    Recording::passed();

    expect(is_file($path))->toBeFalse()
        ->and($this->driver->events)->toContain(['record-stop']);
});

it('keeps the clip of a test that did not pass', function () {
    $path = automaticClip($this->driver);
    Recording::finish($this->driver);

    Recording::finished();
    Recording::passed();

    expect(is_file($path))->toBeTrue();

    unlink($path);
});

it('keeps a clip record() asked for, whatever the outcome', function () {
    Recording::request($this->directory.'/asked.mp4');
    Recording::begin($this->driver, new Device('ios', 'iPhone', true));
    $path = filmed($this->driver);
    Recording::finish($this->driver);

    Recording::passed();

    expect(is_file($path))->toBeTrue();
});

it('hands a running clip to record(), which keeps it at its own path', function () {
    $path = automaticClip($this->driver);

    Recording::request($this->directory.'/mine.mp4');
    Recording::finish($this->driver);
    Recording::passed();

    expect(is_file($path))->toBeFalse()
        ->and(is_file($this->directory.'/mine.mp4'))->toBeTrue()
        ->and(array_filter($this->driver->events, fn (array $event): bool => $event[0] === 'record'))->toHaveCount(1);
});

it('deletes on the passed event and forgets on the finished event', function () {
    $passed = (new ReflectionClass(Passed::class))->newInstanceWithoutConstructor();
    $finished = (new ReflectionClass(Finished::class))->newInstanceWithoutConstructor();

    $kept = automaticClip($this->driver);
    Recording::finish($this->driver);
    (new TestFinished)->notify($finished);
    (new TestPassed)->notify($passed);

    expect(is_file($kept))->toBeTrue();

    unlink($kept);
    $deleted = automaticClip($this->driver);
    Recording::finish($this->driver);
    (new TestPassed)->notify($passed);

    expect(is_file($deleted))->toBeFalse();
});

it('turns on from the configuration', function () {
    Configuration::reset();

    expect(Configuration::resolve()->recordFailures())->toBeFalse();

    Configuration::configure(['record_failures' => true]);

    expect(Configuration::resolve()->recordFailures())->toBeTrue();
});

it('turns on from --record-failures, which phpunit never sees, and reaches a worker', function () {
    Configuration::reset();
    $remaining = (new Plugin)->handleArguments(['vendor/bin/pest', '--record-failures']);

    expect($remaining)->toBe(['vendor/bin/pest'])
        ->and(Configuration::resolve()->recordFailures())->toBeTrue()
        ->and(getenv('NATIVEPHP_SIMULATOR_RECORD_FAILURES'))->toBe('1');

    // A worker's own arguments no longer carry the flag. It reads what the run published.
    (new ReflectionProperty(Arguments::class, 'recordFailures'))->setValue(null, false);
    Arguments::intercept(['vendor/bin/pest']);

    expect(Configuration::resolve()->recordFailures())->toBeTrue();
});

mobile(function () {
    it('starts recording at the first screen, before the app opens', function () {
        permissions([]);

        screen('/lights')->tap('Save');
        screen('/settings');

        $GLOBALS['simulator-passed-clips'][] = filmed($this->driver);

        expect($this->driver->recording)->toStartWith($this->directory.'/simulator-recordings/')
            ->and($this->driver->events)->toBe([
                ['ready'],
                ['record', $this->driver->recording],
                ['install', $this->database],
                ['open', 'myapp://lights'],
                ['ready'],
                ['open', 'myapp://settings'],
            ]);
    });
});

// Runs after the test above, once PHPUnit has said it passed.
it('deleted the clips of the mobile test above, which passed', function () {
    $clips = $GLOBALS['simulator-passed-clips'] ?? [];

    if ($clips === []) {
        $this->markTestSkipped('The mobile test above did not run.');
    }

    expect(array_filter($clips, is_file(...)))->toBe([]);

    foreach ($clips as $clip) {
        if (is_dir(dirname($clip, 2))) {
            removeRecordingDirectory(dirname($clip, 2));
        }
    }
});
