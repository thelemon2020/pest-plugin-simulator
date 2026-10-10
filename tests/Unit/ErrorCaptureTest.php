<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\CommandTimedOut;
use NativePhp\Simulator\Exceptions\CompanionUnresponsive;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\FailureCapture;
use NativePhp\Simulator\MobileTestFilter;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\SuiteRegistration;
use NativePhp\Simulator\TestDatabase;
use Pest\Factories\TestCaseMethodFactory;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;

beforeEach(function () {
    $this->driver = new FakeDriver([[['label' => 'Save', 'role' => 'Button', 'id' => null, 'center' => [100, 200]]]]);
    $this->database = (string) tempnam(sys_get_temp_dir(), 'simulator-');
    // Failures go under the working directory. A directory of its own keeps them apart from
    // another test's, which can run at the same time in another parallel worker.
    $directory = sys_get_temp_dir().'/simulator-error-capture-'.uniqid('', true);
    mkdir($directory);
    $this->directory = (string) realpath($directory);
    $this->cwd = getcwd();
    chdir($this->directory);
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 0]);
    Sessions::fake($this->driver);
    TestDatabase::fake(fn (): string => $this->database);
});

afterEach(function () {
    Sessions::fake(null);
    TestDatabase::fake(null);
    Configuration::reset();
    chdir($this->cwd);
    removeCaptureDirectory($this->directory);

    if (is_file($this->database)) {
        unlink($this->database);
    }
});

function removeCaptureDirectory(string $path): void
{
    foreach (glob($path.'/*') ?: [] as $entry) {
        is_dir($entry) ? removeCaptureDirectory($entry) : unlink($entry);
    }

    rmdir($path);
}

/**
 * Run a test body the way a mobile() suite runs it on an iPhone, and return what it threw.
 *
 * @param  ?class-string<Throwable>  $throws  what the test says it throws, as throws() would
 */
function erroredOnDevice(Closure $body, ?string $throws = null): Throwable
{
    $method = new TestCaseMethodFactory(__FILE__, $body);

    if ($throws !== null) {
        $method->proxies->add(__FILE__, __LINE__, 'expectException', [$throws]);
    }

    SuiteRegistration::run([new Device('ios', 'iPhone 17', true)], function () use ($method): void {
        (new MobileTestFilter)->accept($method);
    });

    try {
        ($method->closure)('ios:1:iPhone 17');
    } catch (Throwable $error) {
        return $error;
    }

    throw new RuntimeException('The test did not throw.');
}

/**
 * @return list<string>
 */
function capturedFailures(string $directory): array
{
    return glob($directory.'/simulator-failures/*') ?: [];
}

it('saves the trace, tree, screenshot, and logs of a test the device broke, and names them in its error', function () {
    $this->driver->describeFailures = 1;
    $this->driver->savedLogs = ['laravel.log' => 'local.ERROR: boom'];

    $error = erroredOnDevice(function (): void {
        permissions([]);
        screen('/lights')->scroll();
    });

    [$root] = capturedFailures($this->directory);
    $trace = json_decode((string) file_get_contents($root.'/trace.json'), true);

    expect($error)->toBeInstanceOf(SimulatorException::class)
        ->and($error->getMessage())->toBe(implode("\n", [
            'window-server frontmost returned no application object',
            '',
            "Saved {$root}/trace.json",
            "Saved {$root}/trace.txt",
            "Saved {$root}/tree.json",
            "Saved {$root}/screen.png",
            "Saved {$root}/laravel.log",
        ]))
        ->and($root)->toMatch('#/simulator-failures/\d{8}-\d{6}$#')
        ->and(array_column($trace['steps'], 'action'))->toBe(['open', 'scroll'])
        ->and($trace['steps'][1])->toMatchArray([
            'target' => 'down',
            'result' => 'error',
            'error' => 'window-server frontmost returned no application object',
            'reads' => 1,
            'failedReads' => 1,
        ])
        ->and(file_get_contents($root.'/trace.txt'))
        ->toContain('error   scroll [down]: 1 read (1 failed); window-server frontmost returned no application object');
});

it('does not ask a companion that stopped answering for the tree', function () {
    $this->driver->describeFailures = 2;
    $this->driver->describeError = fn (): SimulatorException => new CompanionUnresponsive('Companion [accessibility_info] did not answer in time');

    $error = erroredOnDevice(function (): void {
        permissions([]);
        screen('/lights')->scroll();
    });

    [$root] = capturedFailures($this->directory);

    expect($error)->toBeInstanceOf(CompanionUnresponsive::class)
        ->and($error->getMessage())->toBe(implode("\n", [
            'Companion [accessibility_info] did not answer in time',
            '',
            "Saved {$root}/trace.json",
            "Saved {$root}/trace.txt",
            "Saved {$root}/screen.png",
        ]))
        ->and($this->driver->describeFailures)->toBe(1);
});

it('does not ask a companion that stopped answering for the tree when an assertion runs out of time', function () {
    $this->driver->describeFailures = 2;
    $this->driver->describeError = fn (): SimulatorException => new CompanionUnresponsive('Companion [accessibility_info] did not answer in time');
    $root = $this->directory.'/failure';

    try {
        (new Screen($this->driver, timeoutSeconds: 0, failureDirectory: $root))->assertSee('Saved');
    } catch (AssertionFailedError $error) {
    }

    expect($error->getMessage())->toContain('The last attempt to read the screen failed: Companion [accessibility_info] did not answer in time')
        ->and($error->getMessage())->toContain("Saved {$root}/screen.png")
        ->and(is_file($root.'/tree.json'))->toBeFalse()
        ->and($this->driver->describeFailures)->toBe(1);
});

it('reads nothing more from a device whose command ran out of time', function () {
    $this->driver->describeFailures = 2;
    $this->driver->describeError = fn (): SimulatorException => new CommandTimedOut('adb exec-out uiautomator dump /dev/tty did not finish within 60 seconds, so it was stopped.');
    $this->driver->savedLogs = ['laravel.log' => 'local.ERROR: boom'];

    $error = erroredOnDevice(function (): void {
        permissions([]);
        screen('/lights')->scroll();
    });

    [$root] = capturedFailures($this->directory);

    expect($error->getMessage())->toEndWith("so it was stopped.\n\nSaved {$root}/trace.json\nSaved {$root}/trace.txt")
        ->and(array_map(basename(...), glob($root.'/*') ?: []))->toBe(['trace.json', 'trace.txt'])
        ->and($this->driver->describeFailures)->toBe(1);
});

it('traces an open the device refused', function () {
    $this->driver->openError = new SimulatorException('xcrun simctl openurl did not open myapp://lights');

    $error = erroredOnDevice(function (): void {
        permissions([]);
        screen('/lights');
    });

    [$root] = capturedFailures($this->directory);
    $trace = json_decode((string) file_get_contents($root.'/trace.json'), true);

    expect($error->getMessage())->toContain("Saved {$root}/trace.json")
        ->and($trace['steps'])->toHaveCount(1)
        ->and($trace['steps'][0])->toMatchArray([
            'action' => 'open',
            'target' => 'myapp://lights',
            'result' => 'error',
            'error' => 'xcrun simctl openurl did not open myapp://lights',
        ]);
});

it('leaves an error from before the first screen as it was', function () {
    $error = erroredOnDevice(fn () => throw new RuntimeException('No fixtures.'));

    expect($error->getMessage())->toBe('No fixtures.')
        ->and(capturedFailures($this->directory))->toBe([]);
});

it('saves a failed assertion once, from the screen', function () {
    $error = erroredOnDevice(function (): void {
        permissions([]);
        screen('/lights')->assertSee('Saved');
    });

    expect($error)->toBeInstanceOf(AssertionFailedError::class)
        ->and(capturedFailures($this->directory))->toHaveCount(1)
        ->and(substr_count($error->getMessage(), '/trace.json'))->toBe(1);
});

it('saves nothing for a test that expects the error', function () {
    $this->driver->describeFailures = 1;

    $error = erroredOnDevice(function (): void {
        permissions([]);
        screen('/lights')->scroll();
    }, throws: SimulatorException::class);

    expect($error->getMessage())->toBe('window-server frontmost returned no application object')
        ->and(capturedFailures($this->directory))->toBe([]);
});

it('gives each failure in the same second a directory of its own', function () {
    $first = FailureCapture::directory();
    $second = FailureCapture::directory();

    expect($second)->not->toBe($first)
        ->and(is_dir($first))->toBeTrue()
        ->and(is_dir($second))->toBeTrue();
});

mobile(function () {
    it('still passes a test that expects the error', function () {
        $this->driver->describeFailures = 1;
        permissions([]);

        screen('/lights')->scroll();
    })->throws(SimulatorException::class, 'window-server frontmost returned no application object');
});
