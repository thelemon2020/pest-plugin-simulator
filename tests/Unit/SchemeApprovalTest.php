<?php

declare(strict_types=1);

use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\IosDriver;
use NativePhp\Simulator\SchemeApproval;
use Tests\Support\RecordingCommand;

afterEach(function () {
    Configuration::reset();

    foreach (glob(sys_get_temp_dir().'/scheme-approval-*') ?: [] as $root) {
        removeSchemeApprovalRuntime($root);
    }
});

it('reads a title with the app name in place of each kind of placeholder', function () {
    expect(SchemeApproval::titled('Open in “Simulator Sample”?', 'Open in “%@”?'))->toBeTrue()
        ->and(SchemeApproval::titled('In „Simulator Sample“ öffnen?', 'In „%@“ öffnen?'))->toBeTrue()
        ->and(SchemeApproval::titled('“Safari” “Simulator Sample” खोलना चाहता है', '“%1$@” “%2$@” खोलना चाहता है'))->toBeTrue()
        ->and(SchemeApproval::titled('Avataanko Simulator Samplen-apissa?', 'Avataanko %[tt]@-apissa?'))->toBeTrue()
        ->and(SchemeApproval::titled('Open in “Simulator Sample”', 'Open in “%@”?'))->toBeFalse()
        ->and(SchemeApproval::titled('Open in “”?', 'Open in “%@”?'))->toBeFalse()
        ->and(SchemeApproval::titled('Allow “Simulator Sample” to use your location?', 'Open in “%@”?'))->toBeFalse();
});

it('takes both titles and the Open button from one language', function () {
    expect(SchemeApproval::prompt([
        'SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'In „%@“ öffnen?',
        'SCHEME_APPROVAL_PROMPT_TITLE' => 'Die App „%@“ möchte „%@“ öffnen',
        'SCHEME_APPROVAL_OPEN_BUTTON' => 'Öffnen',
        'SCHEME_APPROVAL_DO_NOT_OPEN_BUTTON' => 'Abbrechen',
    ]))->toBe([
        'titles' => ['In „%@“ öffnen?', 'Die App „%@“ möchte „%@“ öffnen'],
        'open' => 'Öffnen',
    ])
        ->and(SchemeApproval::prompt(['SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'In „%@“ öffnen?']))->toBeNull()
        ->and(SchemeApproval::prompt(['SCHEME_APPROVAL_OPEN_BUTTON' => 'Öffnen']))->toBeNull();
});

it('reads every language the runtime has in one plutil call', function () {
    $root = schemeApprovalRuntime(['ar', 'de']);
    $command = new RecordingCommand;
    // What plutil writes for two files: one object after the other, nothing between them.
    // Arabic's quotes are plain ones, escaped in the JSON.
    $command->responder = fn (string $binary, array $arguments): ?string => json_encode([
        'SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'فتح في "%@"؟',
        'SCHEME_APPROVAL_OPEN_BUTTON' => 'فتح',
    ], JSON_UNESCAPED_UNICODE).json_encode([
        'SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'In „%@“ öffnen? {}',
        'SCHEME_APPROVAL_OPEN_BUTTON' => 'Öffnen',
    ], JSON_UNESCAPED_UNICODE);
    $strings = $root.'/System/Library/Frameworks/CoreServices.framework';

    expect(SchemeApproval::read($command, $root, 10.0))->toBe([
        ['titles' => ['فتح في "%@"؟'], 'open' => 'فتح'],
        ['titles' => ['In „%@“ öffnen? {}'], 'open' => 'Öffnen'],
    ])
        ->and($command->calls)->toBe([
            ['plutil', ['-convert', 'json', '-o', '-', $strings.'/ar.lproj/SchemeApproval.strings', $strings.'/de.lproj/SchemeApproval.strings']],
        ])
        ->and(array_values($command->timeouts))->toBe([10.0]);
});

it('reads each file on its own when plutil cannot read one of them, and skips that one', function () {
    $root = schemeApprovalRuntime(['de', 'en', 'xx']);
    $command = new RecordingCommand;
    $command->responder = function (string $binary, array $arguments): ?string {
        $files = array_slice($arguments, 4);

        if (count($files) > 1 || str_contains($files[0], '/xx.lproj/')) {
            throw new SimulatorException('plutil: Property List error: Unexpected character n at line 1');
        }

        return str_contains($files[0], '/de.lproj/')
            ? json_encode(['SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'In „%@“ öffnen?', 'SCHEME_APPROVAL_OPEN_BUTTON' => 'Öffnen'])
            : json_encode(['SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'Open in “%@”?', 'SCHEME_APPROVAL_OPEN_BUTTON' => 'Open']);
    };

    expect(SchemeApproval::read($command, $root))->toBe([
        ['titles' => ['In „%@“ öffnen?'], 'open' => 'Öffnen'],
        ['titles' => ['Open in “%@”?'], 'open' => 'Open'],
    ])
        ->and($command->calls)->toHaveCount(4);
});

it('reads nothing without a runtime', function () {
    $command = new RecordingCommand;

    expect(SchemeApproval::read($command, ''))->toBe([])
        ->and(SchemeApproval::read($command, '/nonexistent/runtime'))->toBe([])
        ->and(SchemeApproval::read($command, schemeApprovalRuntime([])))->toBe([])
        ->and($command->calls)->toBe([]);
});

it('reads the prompt text from the runtime the simulator boots, once, with a short time limit', function () {
    $root = schemeApprovalRuntime(['de']);
    $command = new RecordingCommand;
    $command->responder = fn (string $binary, array $arguments): ?string => match ($binary) {
        'xcrun' => $root."\n",
        'plutil' => json_encode(['SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'In „%@“ öffnen?', 'SCHEME_APPROVAL_OPEN_BUTTON' => 'Öffnen']),
        default => null,
    };
    $driver = schemeApprovalDriver($command);

    $first = $driver->openPrompts();
    $second = $driver->openPrompts();

    expect($first)->toBe([['titles' => ['In „%@“ öffnen?'], 'open' => 'Öffnen']])
        ->and($second)->toBe($first)
        ->and($command->calls)->toBe([
            ['xcrun', ['simctl', 'getenv', 'UDID', 'SIMULATOR_ROOT']],
            ['plutil', ['-convert', 'json', '-o', '-', $root.'/System/Library/Frameworks/CoreServices.framework/de.lproj/SchemeApproval.strings']],
        ])
        ->and(array_values($command->timeouts))->toBe([10.0, 10.0]);
});

it('asks simctl for the runtime again after a failed lookup', function () {
    $root = schemeApprovalRuntime(['de']);
    $command = new RecordingCommand;
    $lookups = 0;
    $command->responder = function (string $binary, array $arguments) use ($root, &$lookups): ?string {
        if ($binary === 'xcrun' && ++$lookups === 1) {
            throw new SimulatorException('xcrun simctl getenv UDID SIMULATOR_ROOT did not finish within 10 seconds, so it was stopped.');
        }

        return $binary === 'xcrun'
            ? $root."\n"
            : json_encode(['SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'In „%@“ öffnen?', 'SCHEME_APPROVAL_OPEN_BUTTON' => 'Öffnen']);
    };
    $driver = schemeApprovalDriver($command);

    expect($driver->openPrompts())->toBe([])
        ->and($driver->openPrompts())->toBe([['titles' => ['In „%@“ öffnen?'], 'open' => 'Öffnen']])
        ->and($lookups)->toBe(2);
});

it('stops asking for the prompt text after three failed lookups', function () {
    $command = new RecordingCommand;
    $command->failures = ['getenv' => 'Invalid device state'];
    $driver = schemeApprovalDriver($command);

    foreach (range(1, 5) as $read) {
        expect($driver->openPrompts())->toBe([]);
    }

    expect($command->calls)->toHaveCount(3);
});

function schemeApprovalDriver(RecordingCommand $command): IosDriver
{
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    return $driver;
}

/**
 * A runtime root with an empty SchemeApproval.strings per language: the tests answer plutil.
 *
 * @param  list<string>  $languages
 */
function schemeApprovalRuntime(array $languages): string
{
    $root = sys_get_temp_dir().'/scheme-approval-'.bin2hex(random_bytes(4));
    mkdir($root.'/System/Library/Frameworks/CoreServices.framework', 0777, true);

    foreach ($languages as $language) {
        $directory = $root.'/System/Library/Frameworks/CoreServices.framework/'.$language.'.lproj';
        mkdir($directory);
        touch($directory.'/SchemeApproval.strings');
    }

    return $root;
}

function removeSchemeApprovalRuntime(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeSchemeApprovalRuntime($path.'/'.$entry);
            }
        }

        rmdir($path);

        return;
    }

    unlink($path);
}
