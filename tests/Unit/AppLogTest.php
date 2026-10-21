<?php

declare(strict_types=1);

use NativePhp\Simulator\AndroidDriver;
use NativePhp\Simulator\AppLog;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\AppError;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Fakes\Records;
use NativePhp\Simulator\IosDriver;
use NativePhp\Simulator\MobileTestFilter;
use NativePhp\Simulator\Sessions;
use NativePhp\Simulator\SuiteRegistration;
use NativePhp\Simulator\TestDatabase;
use Pest\Factories\TestCaseMethodFactory;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeDriver;
use Tests\Support\RecordingCommand;

const IOS_ERROR = <<<'LOG'
[2026-10-20 13:48:02] local.ERROR: spike boom {"exception":"[object] (RuntimeException(code: 0): spike boom at /Users/me/Library/Developer/CoreSimulator/Devices/0ED0/data/Containers/Data/Application/60E5/Documents/app/app/NativeComponents/Spike.php:35)
[stacktrace]
#0 /Users/me/Library/Developer/CoreSimulator/Devices/0ED0/data/Containers/Data/Application/60E5/Documents/app/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): App\\NativeComponents\\Spike->boom()
#1 /Users/me/Library/Developer/CoreSimulator/Devices/0ED0/data/Containers/Data/Application/60E5/Documents/app/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()
#2 {main}
"}

LOG;

const ANDROID_ERROR = <<<'LOG'
[2026-10-20 13:54:09] local.ERROR: Order 2 was paid already. {"exception":"[object] (App\\Exceptions\\AlreadyPaid(code: 0): Order 2 was paid already. at /data/data/com.example.app/app_storage/laravel/app/NativeComponents/Checkout.php:31)
[stacktrace]
#0 /data/data/com.example.app/app_storage/laravel/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): App\\NativeComponents\\Checkout->pay()
"}

LOG;

beforeEach(function () {
    $this->driver = new FakeDriver([[['label' => 'Pay', 'role' => 'Button', 'id' => null, 'center' => [100, 200]]]]);
    $directory = sys_get_temp_dir().'/simulator-app-log-'.uniqid('', true);
    mkdir($directory);
    $this->directory = (string) realpath($directory);
    $this->cwd = getcwd();
    chdir($this->directory);
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 0]);
    Sessions::fake($this->driver);
    TestDatabase::fake(fn (): ?string => null);
    AppLog::begin();
});

afterEach(function () {
    Sessions::fake(null);
    TestDatabase::fake(null);
    Configuration::reset();
    AppLog::begin();
    chdir($this->cwd);
    exec('rm -rf '.escapeshellarg($this->directory));
});

/**
 * Runs a test body the way a mobile() suite runs it on an iPhone, and returns what it
 * threw, or null when it passed.
 */
function onDevice(Closure $body): ?Throwable
{
    $method = new TestCaseMethodFactory(__FILE__, $body);

    SuiteRegistration::run([new Device('ios', 'iPhone 17', true)], function () use ($method): void {
        (new MobileTestFilter)->accept($method);
    });

    try {
        ($method->closure)('ios:1:iPhone 17');
    } catch (Throwable $error) {
        return $error;
    }

    return null;
}

/**
 * @return list<string>
 */
function failureDirectories(string $directory): array
{
    return glob($directory.'/simulator-failures/*') ?: [];
}

it('reads the exception, where it was thrown, and the first frames from an entry', function () {
    [$ios] = AppLog::parse(IOS_ERROR);
    [$android] = AppLog::parse("[2026-10-20 13:54:08] local.INFO: Paying\n".ANDROID_ERROR);

    expect($ios)->toMatchArray([
        'level' => 'ERROR',
        'class' => 'RuntimeException',
        'message' => 'spike boom',
        'where' => 'app/NativeComponents/Spike.php:35',
        'frames' => [
            '#0 vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): App\NativeComponents\Spike->boom()',
            '#1 vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\Container\BoundMethod::{closure:Illuminate\Container\BoundMethod::call():35}()',
            '#2 {main}',
        ],
    ])
        ->and(AppLog::parse("[2026-10-20 13:54:08] local.INFO: Paying\n".ANDROID_ERROR))->toHaveCount(2)
        ->and($android)->toMatchArray(['level' => 'INFO', 'message' => 'Paying', 'class' => null]);

    [, $error] = AppLog::parse("[2026-10-20 13:54:08] local.INFO: Paying\n".ANDROID_ERROR);

    expect($error)->toMatchArray([
        'class' => 'App\Exceptions\AlreadyPaid',
        'message' => 'Order 2 was paid already.',
        'where' => 'app/NativeComponents/Checkout.php:31',
    ]);
});

it('fails the step that made the app log an error, as an error in the trace', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = "[2026-10-20 13:00:00] local.ERROR: from an earlier test\n";
    $this->driver->pulling = function (string $pattern): void {
        if (count($this->driver->taps) === 1 && ! str_contains($this->driver->appFiles['storage/logs/laravel.log'], 'Order 2')) {
            $this->driver->appFiles['storage/logs/laravel.log'] .= ANDROID_ERROR;
        }
    };

    $error = onDevice(function (): void {
        permissions([]);
        screen('/checkout')->tap('Pay')->assertSee('Paid');
    });

    [$root] = failureDirectories($this->directory);
    $trace = json_decode((string) file_get_contents($root.'/trace.json'), true);

    expect($error)->toBeInstanceOf(AppError::class)
        ->and($error->getMessage())->toStartWith(implode("\n", [
            'The app logged App\Exceptions\AlreadyPaid: Order 2 was paid already.',
            '',
            'Thrown at app/NativeComponents/Checkout.php:31',
            '',
            '#0 vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): App\NativeComponents\Checkout->pay()',
            '',
            'laravel.log has the whole entry. Call allowAppErrors() in a test where the app is meant to log errors, or expectAppError(AlreadyPaid::class) for one it is meant to throw.',
            '',
            "Saved {$root}/trace.json",
        ]))
        ->and(array_column($trace['steps'], 'action'))->toBe(['open', 'tap'])
        ->and($trace['steps'][1]['result'])->toBe('error')
        ->and($trace['steps'][1]['error'])->toStartWith('The app logged App\Exceptions\AlreadyPaid');
});

it('ends a wait within a second of the error, not at the timeout', function () {
    Configuration::configure(['timeout' => 15.0]);
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $this->driver->reading = function (): array {
        if ($this->driver->descriptions === 2) {
            $this->driver->appFiles['storage/logs/laravel.log'] .= IOS_ERROR;
        }

        return [['label' => 'Something went wrong', 'role' => 'StaticText', 'id' => null, 'center' => [10, 10]]];
    };
    $started = microtime(true);

    $error = onDevice(function (): void {
        permissions([]);
        screen('/boom')->assertSee('Boomed');
    });

    expect($error)->toBeInstanceOf(AppError::class)
        ->and($error->getMessage())->toStartWith('The app logged RuntimeException: spike boom')
        ->and(microtime(true) - $started)->toBeLessThan(3.0);
});

it('lets a test allow the app its errors', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $this->driver->pulling = function (): void {
        if ($this->driver->taps !== []) {
            $this->driver->appFiles['storage/logs/laravel.log'] = IOS_ERROR;
        }
    };

    expect(onDevice(function (): void {
        allowAppErrors();
        permissions([]);
        screen('/boom')->tap('Pay')->assertSee('Pay');
    }))->toBeNull();
});

it('passes a test that expects the error the app logged, and its subclasses', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $this->driver->pulling = function (): void {
        if ($this->driver->taps !== []) {
            $this->driver->appFiles['storage/logs/laravel.log'] = IOS_ERROR;
        }
    };

    expect(onDevice(function (): void {
        expectAppError(Exception::class);
        permissions([]);
        screen('/boom')->tap('Pay')->assertSee('Pay');
    }))->toBeNull();
});

it('fails a test whose expected error the app never logged', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = '';

    $error = onDevice(function (): void {
        expectAppError('App\Exceptions\AlreadyPaid');
        permissions([]);
        screen('/checkout')->tap('Pay');
    });

    expect($error)->toBeInstanceOf(AssertionFailedError::class)
        ->and($error->getMessage())->toBe('The app did not log [App\Exceptions\AlreadyPaid], which expectAppError() waited for.');
});

it('still fails on an error the test did not expect', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $this->driver->pulling = function (): void {
        if ($this->driver->taps !== []) {
            $this->driver->appFiles['storage/logs/laravel.log'] = IOS_ERROR;
        }
    };

    $error = onDevice(function (): void {
        expectAppError('App\Exceptions\AlreadyPaid');
        permissions([]);
        screen('/boom')->tap('Pay');
    });

    expect($error)->toBeInstanceOf(AppError::class);
});

it('does not read the log when app_errors is off', function () {
    Configuration::configure(['app_errors' => false]);
    $this->driver->appFiles['storage/logs/laravel.log'] = IOS_ERROR;

    expect(onDevice(function (): void {
        permissions([]);
        screen('/boom')->tap('Pay');
    }))->toBeNull()
        ->and($this->driver->tails)->toBe([]);
});

it('ignores warnings and what the app logged before the test', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = IOS_ERROR;
    $this->driver->pulling = function (): void {
        if ($this->driver->taps !== []) {
            $this->driver->appFiles['storage/logs/laravel.log'] .= "[2026-10-20 13:49:00] local.WARNING: Slow query\n";
        }
    };

    expect(onDevice(function (): void {
        permissions([]);
        screen('/boom')->tap('Pay')->assertSee('Pay');
    }))->toBeNull();
});

it('reads the daily channel\'s newest file, from its start once a new one begins', function () {
    $this->driver->appFiles['storage/logs/laravel-2026-10-19.log'] = IOS_ERROR;
    $this->driver->pulling = function (): void {
        if ($this->driver->taps !== []) {
            $this->driver->appFiles['storage/logs/laravel-2026-10-20.log'] = ANDROID_ERROR;
        }
    };

    $error = onDevice(function (): void {
        permissions([]);
        screen('/checkout')->tap('Pay');
    });

    expect($error)->toBeInstanceOf(AppError::class)
        ->and($error->getMessage())->toStartWith('The app logged App\Exceptions\AlreadyPaid');
});

it('reads a log that was started again from its start', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = str_repeat("[2026-10-20 13:00:00] local.INFO: filler\n", 50);
    $this->driver->pulling = function (): void {
        if ($this->driver->taps !== []) {
            $this->driver->appFiles['storage/logs/laravel.log'] = ANDROID_ERROR;
        }
    };

    expect(onDevice(function (): void {
        permissions([]);
        screen('/checkout')->tap('Pay');
    }))->toBeInstanceOf(AppError::class);
});

it('waits for an entry the app has only half written', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    AppLog::start($this->driver);
    $this->driver->appFiles['storage/logs/laravel.log'] = substr(ANDROID_ERROR, 0, 60);

    AppLog::check($this->driver);

    $this->driver->appFiles['storage/logs/laravel.log'] = ANDROID_ERROR;

    expect(fn () => AppLog::check($this->driver))->toThrow(AppError::class, 'The app logged App\Exceptions\AlreadyPaid: Order 2 was paid already.');
});

it('tails a file in the ios app container', function () {
    $container = $this->directory.'/container';
    $logs = $container.'/Library/Application Support/storage/logs';
    mkdir($logs, 0777, true);
    file_put_contents($logs.'/laravel-2026-10-19.log', 'old');
    touch($logs.'/laravel-2026-10-19.log', time() - 60);
    file_put_contents($logs.'/laravel-2026-10-20.log', 'abcdef');
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), new RecordingCommand($container));
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    $today = 'storage/logs/laravel-2026-10-20.log';

    expect($driver->tailAppFile('storage/logs/laravel*.log', 2, $today))->toBe(['path' => $today, 'size' => 6, 'bytes' => 'cdef'])
        ->and($driver->tailAppFile('storage/logs/laravel*.log', 10, $today))->toBe(['path' => $today, 'size' => 6, 'bytes' => 'abcdef'])
        // The offset counted in yesterday's file, so today's is read whole.
        ->and($driver->tailAppFile('storage/logs/laravel*.log', 2, 'storage/logs/laravel-2026-10-19.log'))->toBe(['path' => $today, 'size' => 6, 'bytes' => 'abcdef'])
        ->and($driver->tailAppFile('storage/logs/laravel*.log', null))->toBe(['path' => $today, 'size' => 6, 'bytes' => ''])
        ->and($driver->tailAppFile('storage/logs/laravel.log', 0))->toBeNull();
});

it('tails a file in the android app with one run-as shell', function () {
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $command = new RecordingCommand;
    $command->outputs = ['exec-out' => "Ystorage/logs/laravel.log\n120\nnew bytes\n"];
    $driver = new AndroidDriver(new Device('android', 'Pixel 8', true), Configuration::resolve(), $command);
    (new ReflectionProperty(AndroidDriver::class, 'serial'))->setValue($driver, 'emulator-5554');

    expect($driver->tailAppFile('storage/logs/laravel.log', 110, 'storage/logs/laravel.log'))->toBe(['path' => 'storage/logs/laravel.log', 'size' => 120, 'bytes' => "new bytes\n"]);

    $script = $command->calls[0][1][3];

    expect($command->calls[0][1][2])->toBe('exec-out')
        ->and($script)->toStartWith("run-as 'com.example.app' sh -c ")
        ->and($script)->toContain('ls -t storage/logs/laravel.log')
        ->and($script)->toContain('tail -c +111')
        ->and($script)->toContain('-ge 110');

    // Only the size: no tail and no cat, so a big log is not sent over adb.
    $command->outputs = ['exec-out' => "Ystorage/logs/laravel.log\n120\n"];

    expect($driver->tailAppFile('storage/logs/laravel.log', null))->toBe(['path' => 'storage/logs/laravel.log', 'size' => 120, 'bytes' => ''])
        ->and($command->calls[1][1][3])->not->toContain('tail')
        ->and($command->calls[1][1][3])->not->toContain('cat');

    $command->failures = ['exec-out' => 'adb: device offline'];

    expect(fn () => $driver->tailAppFile('storage/logs/laravel.log', null))->toThrow(SimulatorException::class);
    $command->failures = [];

    $command->outputs = ['exec-out' => 'N'];

    expect($driver->tailAppFile('storage/logs/laravel-*.log', 0))->toBeNull()
        ->and(fn () => $driver->tailAppFile('storage/logs/$(reboot).log', 0))->toThrow(SimulatorException::class);
});

it('reads only the size when a test starts, and never takes an old error for a new one', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = IOS_ERROR;
    $this->driver->tailFails = true;
    AppLog::start($this->driver);
    $this->driver->tailFails = false;

    // The first read failed, so where the log ended is learned now, not read from 0.
    AppLog::check($this->driver);
    AppLog::check($this->driver);

    $this->driver->appFiles['storage/logs/laravel.log'] = IOS_ERROR.ANDROID_ERROR;

    expect(fn () => AppLog::check($this->driver))->toThrow(AppError::class, 'App\Exceptions\AlreadyPaid');
});

it('reads a new day\'s file from its start, even when it is longer than the last one', function () {
    $this->driver->appFiles['storage/logs/laravel-2026-10-19.log'] = 'short line'."\n";
    AppLog::start($this->driver);
    $this->driver->appFiles['storage/logs/laravel-2026-10-20.log'] = ANDROID_ERROR;

    expect(fn () => AppLog::check($this->driver))->toThrow(AppError::class, 'App\Exceptions\AlreadyPaid')
        ->and(AppLog::path())->toBe('storage/logs/laravel-2026-10-20.log');
});

it('finds the exception after other context, and in a message over more than one line', function () {
    $withUser = str_replace('{"exception"', '{"userId":1,"exception"', ANDROID_ERROR);
    $multiLine = <<<'LOG'
[2026-10-20 13:54:09] local.ERROR: Could not pay.
The card was declined. {"exception":"[object] (App\\Exceptions\\Declined(code: 0): Could not pay.
The card was declined. at /data/data/com.example.app/app_storage/laravel/app/Pay.php:9)
[stacktrace]
#0 /data/data/com.example.app/app_storage/laravel/app/Pay.php(9): pay()
"}

LOG;

    [$first] = AppLog::parse($withUser);
    [$second] = AppLog::parse($multiLine);

    expect($first['class'])->toBe('App\Exceptions\AlreadyPaid')
        ->and($first['message'])->toBe('Order 2 was paid already.')
        ->and($second['class'])->toBe('App\Exceptions\Declined')
        ->and($second['message'])->toBe("Could not pay.\nThe card was declined.")
        ->and($second['where'])->toBe('app/Pay.php:9')
        ->and($second['frames'])->toBe(['#0 app/Pay.php(9): pay()']);
});

it('names the app\'s error when an assertion gave up before the log was read again', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $this->reads = 0;
    // Logged after screen() noted where the log ended.
    $this->driver->pulling = function (string $pattern): void {
        if (++$this->reads > 1) {
            $this->driver->appFiles['storage/logs/laravel.log'] = ANDROID_ERROR;
        }
    };

    $error = onDevice(function (): void {
        screen('/checkout')->assertSee('Done');
    });

    // Still a failed assertion, as PHPUnit counts it, with the app's error first.
    expect($error)->toBeInstanceOf(AssertionFailedError::class)
        ->and($error->getMessage())->toStartWith('The app logged App\Exceptions\AlreadyPaid')
        ->and($error->getMessage())->toContain('The step had failed first: ')
        ->and($error->getPrevious())->toBeInstanceOf(AppError::class);
});

it('reads the log once more when a test ends on a tap', function () {
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $this->reads = 0;
    $this->driver->pulling = function (string $pattern): void {
        // The app's PHP answers the tap only after the step that sent it has ended.
        if ($this->driver->taps !== [] && ++$this->reads > 1) {
            $this->driver->appFiles['storage/logs/laravel.log'] = ANDROID_ERROR;
        }
    };

    expect(onDevice(function (): void {
        screen('/checkout')->tap('Pay');
    }))->toBeInstanceOf(AppError::class);
});

it('says why expectAppError() cannot see anything when the test never read the log', function () {
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 5, 'app_errors' => false]);
    $started = microtime(true);

    $error = onDevice(function (): void {
        expectAppError(RuntimeException::class);
        screen('/checkout');
    });

    expect($error)->toBeInstanceOf(AssertionFailedError::class)
        ->and($error->getMessage())->toContain('while app_errors is turned off')
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});

it('never reads an old entry again when the app writes more than one log', function () {
    // An earlier test's error is in laravel.log; the daily file and another channel sit beside it.
    $this->driver->appFiles['storage/logs/laravel.log'] = IOS_ERROR;
    $this->driver->appFiles['storage/logs/laravel-2026-10-20.log'] = "[2026-10-20 09:00:00] local.INFO: started\n";
    $this->driver->appFiles['storage/logs/laravel-queries.log'] = "[2026-10-20 09:00:00] local.INFO: select 1\n";
    AppLog::start($this->driver);

    $this->driver->appFiles['storage/logs/laravel-queries.log'] .= "[2026-10-20 09:00:01] local.INFO: select 2\n";
    $this->driver->appFiles['storage/logs/laravel-2026-10-20.log'] .= "[2026-10-20 09:00:01] local.INFO: tapped\n";
    $this->driver->appFiles['storage/logs/laravel.log'] .= "[2026-10-20 09:00:02] local.INFO: still fine\n";

    AppLog::check($this->driver);
    AppLog::check($this->driver);

    $this->driver->appFiles['storage/logs/laravel-2026-10-20.log'] .= ANDROID_ERROR;

    expect(fn () => AppLog::check($this->driver))->toThrow(AppError::class, 'App\Exceptions\AlreadyPaid')
        ->and(AppLog::path())->toBe('storage/logs/laravel-2026-10-20.log');
});

it('does not wait at the end after an assert has already waited for the app', function () {
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 5]);
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $started = microtime(true);

    $error = onDevice(function (): void {
        screen('/checkout')->tap('Pay');
        // What Mail::assertSent() and the other fakes' asserts do once they read the app.
        Records::settled(fn () => null, fn () => null);
    });

    expect($error)->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(0.8);
});

it('does not wait at the end of a test that ends on a screenshot', function () {
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 5]);
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $path = $this->directory.'/shot.png';
    $started = microtime(true);

    expect(onDevice(function () use ($path): void {
        screen('/checkout')->tap('Pay')->screenshot($path);
    }))->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(0.8);
});

it('stops waiting at the end once the app says PHP has taken the tap', function () {
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 5]);
    $this->driver = new FakeDriver([[
        ['label' => 'Pay', 'role' => 'Button', 'id' => null, 'center' => [100, 200]],
        ['label' => '0', 'role' => 'StaticText', 'id' => 'nativephp-pending', 'center' => [1, 1]],
    ]]);
    Sessions::fake($this->driver);
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $started = microtime(true);

    expect(onDevice(function (): void {
        screen('/checkout')->tap('Pay');
    }))->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(0.9);
});

it('still waits the second at the end without the marker, for the tap\'s error', function () {
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 5]);
    $this->driver->appFiles['storage/logs/laravel.log'] = '';
    $started = microtime(true);

    expect(onDevice(function (): void {
        screen('/checkout')->tap('Pay');
    }))->toBeNull()
        ->and(microtime(true) - $started)->toBeGreaterThan(0.95);
});
