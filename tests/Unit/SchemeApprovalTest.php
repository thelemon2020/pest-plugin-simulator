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

it('reads every language the runtime has, and skips one plutil cannot read', function () {
    $root = schemeApprovalRuntime(['de', 'en', 'xx']);
    $command = new RecordingCommand;
    $command->responder = function (string $binary, array $arguments): ?string {
        $file = (string) end($arguments);

        return match (true) {
            str_contains($file, '/de.lproj/') => json_encode([
                'SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'In „%@“ öffnen?',
                'SCHEME_APPROVAL_OPEN_BUTTON' => 'Öffnen',
            ]),
            str_contains($file, '/en.lproj/') => json_encode([
                'SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'Open in “%@”?',
                'SCHEME_APPROVAL_OPEN_BUTTON' => 'Open',
            ]),
            default => throw new SimulatorException('plutil: invalid property list'),
        };
    };

    expect(SchemeApproval::read($command, $root))->toBe([
        ['titles' => ['In „%@“ öffnen?'], 'open' => 'Öffnen'],
        ['titles' => ['Open in “%@”?'], 'open' => 'Open'],
    ])
        ->and($command->calls[0])->toBe(['plutil', ['-convert', 'json', '-o', '-', $root.'/System/Library/Frameworks/CoreServices.framework/de.lproj/SchemeApproval.strings']])
        ->and(count($command->calls))->toBe(3);
});

it('reads nothing without a runtime', function () {
    $command = new RecordingCommand;

    expect(SchemeApproval::read($command, ''))->toBe([])
        ->and(SchemeApproval::read($command, '/nonexistent/runtime'))->toBe([])
        ->and($command->calls)->toBe([]);
});

it('reads the prompt text from the runtime the simulator boots, once', function () {
    $root = schemeApprovalRuntime(['de']);
    $command = new RecordingCommand;
    $command->responder = fn (string $binary, array $arguments): ?string => match ($binary) {
        'xcrun' => $root."\n",
        'plutil' => json_encode(['SCHEME_APPROVAL_PROMPT_TITLE_NO_SOURCE' => 'In „%@“ öffnen?', 'SCHEME_APPROVAL_OPEN_BUTTON' => 'Öffnen']),
        default => null,
    };
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    $first = $driver->openPrompts();
    $second = $driver->openPrompts();

    expect($first)->toBe([['titles' => ['In „%@“ öffnen?'], 'open' => 'Öffnen']])
        ->and($second)->toBe($first)
        ->and($command->calls)->toBe([
            ['xcrun', ['simctl', 'getenv', 'UDID', 'SIMULATOR_ROOT']],
            ['plutil', ['-convert', 'json', '-o', '-', $root.'/System/Library/Frameworks/CoreServices.framework/de.lproj/SchemeApproval.strings']],
        ]);
});

it('falls back to no prompt text when simctl cannot name the runtime', function () {
    $command = new RecordingCommand;
    $command->failures = ['getenv' => 'Invalid device state'];
    Configuration::configure(['bundle_id' => 'com.example.app']);
    $driver = new IosDriver(new Device('ios', 'iPhone 17', true), Configuration::resolve(), $command);
    (new ReflectionProperty(IosDriver::class, 'udid'))->setValue($driver, 'UDID');

    expect($driver->openPrompts())->toBe([])
        ->and($driver->openPrompts())->toBe([])
        ->and(count($command->calls))->toBe(1);
});

/**
 * A runtime root with an empty SchemeApproval.strings per language: the tests answer plutil.
 *
 * @param  list<string>  $languages
 */
function schemeApprovalRuntime(array $languages): string
{
    $root = sys_get_temp_dir().'/scheme-approval-'.bin2hex(random_bytes(4));

    foreach ($languages as $language) {
        $directory = $root.'/System/Library/Frameworks/CoreServices.framework/'.$language.'.lproj';
        mkdir($directory, 0777, true);
        touch($directory.'/SchemeApproval.strings');
    }

    return $root;
}
