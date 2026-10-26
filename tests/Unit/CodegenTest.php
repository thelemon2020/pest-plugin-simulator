<?php

declare(strict_types=1);

use NativePhp\Simulator\Codegen\Script;
use NativePhp\Simulator\Codegen\Selector;
use NativePhp\Simulator\Codegen\Server;
use NativePhp\Simulator\Codegen\Session;
use NativePhp\Simulator\Codegen\Step;
use NativePhp\Simulator\Codegen\Verb;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Fakes\FakeSync;
use NativePhp\Simulator\Fakes\Payload;
use NativePhp\Simulator\Screen;
use NativePhp\Simulator\Sessions;
use Tests\Support\FakeDriver;

$element = fn (string $label, array $frame, string $role = 'Button', array $extra = []): array => array_replace([
    'label' => $label,
    'role' => $role,
    'id' => null,
    'center' => [$frame[0] + $frame[2] / 2, $frame[1] + $frame[3] / 2],
    'frame' => $frame,
], $extra);

$orders = fn () => [
    $element('Orders', [0, 40, 390, 44], 'StaticText', ['chrome' => 'navigation']),
    $element('Order 1', [16, 100, 200, 20], 'StaticText'),
    $element('Pay', [300, 100, 60, 30]),
    $element('Order 2', [16, 160, 200, 20], 'StaticText'),
    $element('Pay', [300, 160, 60, 30]),
    $element('Order 3', [16, 220, 200, 20], 'StaticText'),
    $element('Pay', [300, 220, 60, 30]),
];

it('finds the smallest control under a point', function () use ($element) {
    $card = $element('Card', [0, 0, 390, 300], 'Other');
    $save = $element('Save', [20, 20, 100, 40]);

    expect((new Selector)->at([$card, $save], 50, 30))->toBe($save)
        ->and((new Selector)->at([$card, $save], 200, 200))->toBe($card)
        ->and((new Selector)->at([$card, $save], 500, 500))->toBeNull();
});

it('prefers a control you can touch over the text inside it', function () use ($element) {
    $row = $element('Wi-Fi', [0, 100, 390, 44], 'Switch');
    $text = $element('Wi-Fi', [16, 110, 60, 20], 'StaticText');

    expect((new Selector)->at([$row, $text], 30, 120))->toBe($row)
        ->and((new Selector)->at([$row, $text], 30, 120, touch: false))->toBe($text);
});

it('takes the label of an Android checkbox over its unnamed square', function () use ($element) {
    $label = $element('Subscribe', [150, 600, 160, 57], 'android.widget.TextView', ['checkable' => true]);
    $square = $element('', [160, 610, 46, 37], 'Switch', ['checkable' => true]);

    expect((new Selector)->at([$label, $square], 183, 628))->toBe($label);
});

it('names a control by its accessibility id first', function () use ($element) {
    $save = $element('Save', [20, 20, 100, 40], extra: ['id' => 'save-button']);

    expect((new Selector)->for([$save, $element('Cancel', [20, 80, 100, 40])], $save))
        ->toBe(['target' => 'save-button', 'nth' => null, 'within' => null]);
});

it('skips an Android resource id the app did not choose', function () use ($element) {
    $save = $element('Save', [20, 20, 100, 40], extra: ['id' => 'android:id/button1']);

    expect((new Selector)->for([$save], $save)['target'])->toBe('Save');
});

it('skips the icon name iOS gives a nav bar button', function () use ($element) {
    $back = $element('Back', [20, 66, 36, 36], extra: ['id' => 'chevron.backward', 'chrome' => 'navigation']);

    expect((new Selector)->for([$back], $back)['target'])->toBe('Back')
        ->and(Verb::touch($back, fn () => ['target' => 'Back', 'nth' => null, 'within' => null])->code())->toBe('->goBack()');
});

it('names a control by its label when nothing else shares it', function () use ($orders) {
    $screen = $orders();

    expect((new Selector)->for($screen, $screen[1]))
        ->toBe(['target' => 'Order 1', 'nth' => null, 'within' => null]);
});

it('counts with nth() between controls with the same label', function () use ($orders) {
    $screen = $orders();

    expect((new Selector)->for($screen, $screen[4]))
        ->toBe(['target' => 'Pay', 'nth' => 2, 'within' => null])
        ->and((new Selector)->for($screen, $screen[6])['nth'])->toBe(3);
});

it('looks within a container with an id before counting', function () use ($element) {
    $screen = [
        $element('', [0, 90, 390, 50], 'Cell', ['id' => 'order-1']),
        $element('Pay', [300, 100, 60, 30]),
        $element('', [0, 150, 390, 50], 'Cell', ['id' => 'order-2']),
        $element('Pay', [300, 160, 60, 30]),
    ];

    expect((new Selector)->for($screen, $screen[3]))
        ->toBe(['target' => 'Pay', 'nth' => null, 'within' => 'order-2']);
});

it('says so when nothing names the control', function () use ($element) {
    $icon = $element('', [16, 100, 40, 40], 'Image');

    (new Selector)->for([$icon], $icon);
})->throws(SimulatorException::class, 'no label or accessibility id');

it('turns a touch into the verb for the control', function () use ($element) {
    $at = fn () => ['target' => 'X', 'nth' => null, 'within' => null];

    expect(Verb::touch($element('Save', [0, 0, 10, 10]), $at)->code())->toBe("->tap('X')")
        ->and(Verb::touch($element('Name', [0, 0, 10, 10], 'TextField'), $at, 'Ada')->code())->toBe("->type('X', 'Ada')")
        ->and(Verb::touch($element('Wi-Fi', [0, 0, 10, 10], 'Switch', ['checked' => false, 'checkable' => true]), $at)->code())->toBe("->toggle('X', true)")
        ->and(Verb::touch($element('Agree', [0, 0, 10, 10], 'android.widget.TextView', ['checked' => true, 'checkable' => true]), $at)->code())->toBe("->toggle('X', false)")
        ->and(Verb::touch($element('Pro', [0, 0, 10, 10], 'Button', ['checked' => false, 'checkable' => true]), $at)->code())->toBe("->tap('X')")
        ->and(Verb::touch($element('arrow_back', [0, 0, 10, 10]), $at)->code())->toBe('->goBack()')
        ->and(Verb::touch($element('Activity', [0, 0, 10, 10], 'Button', ['chrome' => 'tab']), $at)->code())->toBe("->changeTab('Activity')")
        ->and(Verb::touch($element('Delete', [0, 0, 10, 10], 'Button', ['chrome' => 'alert']), $at)->code())->toBe("->alert('Delete')");
});

it('asks for the text a field needs', function () use ($element) {
    Verb::touch($element('Name', [0, 0, 10, 10], 'TextField'), fn () => ['target' => 'Name', 'nth' => null, 'within' => null]);
})->throws(SimulatorException::class, 'Type the text');

it('turns a click in assert mode into the check for the control', function () use ($element) {
    $at = fn () => ['target' => 'X', 'nth' => 2, 'within' => null];

    expect(Verb::check($element('Paid', [0, 0, 10, 10], 'StaticText'), $at)->code())->toBe("->assertSee('Paid')")
        ->and(Verb::check($element('Name', [0, 0, 10, 10], 'TextField', ['value' => 'Ada']), $at)->code())->toBe("->nth(2)->assertValue('X', 'Ada')")
        ->and(Verb::check($element('Wi-Fi', [0, 0, 10, 10], 'Switch', ['checked' => true]), $at)->code())->toBe("->nth(2)->assertChecked('X')")
        ->and(Verb::check($element('Orders', [0, 0, 10, 10], 'StaticText', ['chrome' => 'navigation']), $at)->code())->toBe("->assertNavTitle('Orders')")
        ->and(Verb::check($element('Home', [0, 0, 10, 10], 'Button', ['chrome' => 'tab', 'selected' => true]), $at)->code())->toBe("->assertTabActive('Home')");
});

it('writes a step inside within() as a closure', function () {
    expect((new Step('tap', ['Pay'], nth: 2, within: 'order-2'))->code())
        ->toBe("->within('order-2', fn (\$screen) => \$screen->nth(2)->tap('Pay'))");
});

it('writes the chain and the test file', function () {
    $script = new Script('/orders');
    $script->add(new Step('tap', ['Pay'], nth: 2));
    $script->add(new Step('assertSee', ["Paid, it's done"]));

    expect($script->chain())->toBe(<<<'PHP'
        screen('/orders')
            ->nth(2)->tap('Pay')
            ->assertSee('Paid, it\'s done');
        PHP)
        ->and($script->file('pays an order'))->toBe(<<<'PHP'
        <?php

        mobile(function () {
            it('pays an order', function () {
                screen('/orders')
                    ->nth(2)->tap('Pay')
                    ->assertSee('Paid, it\'s done');
            });
        });

        PHP);
});

it('drops the last step on undo', function () {
    $script = new Script('/');
    $script->add(new Step('tap', ['A']));
    $script->add(new Step('tap', ['B']));
    $script->undo();

    expect($script->chain())->toBe("screen('/')\n    ->tap('A');");
});

it('runs a click on the device and records it', function () use ($orders) {
    $driver = new FakeDriver([$orders()]);
    $server = new Server(new Session($driver, new Screen($driver, timeoutSeconds: 0.5), '/orders'), fn (string $name): string => '');

    [$status, , $body] = $server->handle('POST', '/click', json_encode(['x' => 330, 'y' => 175, 'mode' => 'tap']), '127.0.0.1:8899');

    expect($status)->toBe(200)
        ->and(json_decode($body, true))->toBe([
            'line' => "->nth(2)->tap('Pay')",
            'code' => "screen('/orders')\n    ->nth(2)->tap('Pay');",
        ])
        ->and($driver->taps)->toBe([[330.0, 175.0]]);
});

it('keeps a step that failed out of the test', function () use ($element) {
    $driver = new FakeDriver([[$element('Paid', [0, 0, 100, 20], 'StaticText')], []]);
    $session = new Session($driver, new Screen($driver, timeoutSeconds: 0.2, failureDirectory: sys_get_temp_dir().'/codegen-test'), '/');
    $server = new Server($session, fn (string $name): string => '');

    [$status, , $body] = $server->handle('POST', '/click', json_encode(['x' => 10, 'y' => 10, 'mode' => 'assert']), '127.0.0.1:8899');

    expect($status)->toBe(422)
        ->and(json_decode($body, true)['error'])->toContain('Paid')
        ->and($session->script->chain())->toBe("screen('/');");
});

it('saves the test once, under a name from what it does', function () use ($orders) {
    $directory = sys_get_temp_dir().'/codegen-'.uniqid();
    $driver = new FakeDriver([$orders()]);
    $server = new Server(new Session($driver, new Screen($driver, timeoutSeconds: 0.5), '/orders'), fn (string $name): string => $directory.'/'.Session::fileName($name));
    $server->handle('POST', '/click', json_encode(['x' => 330, 'y' => 115, 'mode' => 'tap']), '127.0.0.1:8899');

    [$status, , $body] = $server->handle('POST', '/save', json_encode(['name' => 'pays the first order']), '127.0.0.1:8899');
    [$again] = $server->handle('POST', '/save', json_encode(['name' => 'pays the first order']), '127.0.0.1:8899');

    expect($status)->toBe(200)
        ->and(json_decode($body, true)['file'])->toBe($directory.'/PaysTheFirstOrderTest.php')
        ->and(file_get_contents($directory.'/PaysTheFirstOrderTest.php'))->toContain("it('pays the first order'")->toContain("->nth(1)->tap('Pay')")
        ->and($again)->toBe(422);
});

it('only answers the recording page on this machine', function () use ($orders) {
    $driver = new FakeDriver([$orders()]);
    $server = new Server(new Session($driver, new Screen($driver, timeoutSeconds: 0.5), '/orders'), fn (string $name): string => '');
    $click = json_encode(['x' => 330, 'y' => 175, 'mode' => 'tap']);

    expect($server->handle('POST', '/click', $click, '127.0.0.1:8899', 'https://evil.example')[0])->toBe(403)
        ->and($server->handle('POST', '/click', $click, 'rebound.example:8899')[0])->toBe(403)
        ->and($server->handle('POST', '/click', $click, null)[0])->toBe(403)
        ->and($driver->taps)->toBe([])
        ->and($server->handle('GET', '/state', '', 'localhost:8899', 'http://localhost:8899')[0])->toBe(200);
});

it('checks for text a selector could not name', function () use ($element) {
    // Android reads a Button's label as a TextView of its own, which nth() does not count.
    $rows = [
        $element('Pay', [300, 100, 60, 30]),
        $element('Pay', [310, 105, 40, 20], 'android.widget.TextView'),
        $element('Pay', [300, 160, 60, 30]),
        $element('Pay', [310, 165, 40, 20], 'android.widget.TextView'),
    ];
    $driver = new FakeDriver([$rows]);
    $session = new Session($driver, new Screen($driver, timeoutSeconds: 0.5), '/orders');

    expect($session->click(330, 175, check: true))->toBe(['line' => "->assertSee('Pay')"]);
});

it('checks an empty field by the text it shows, not its value', function () use ($element) {
    $at = fn () => ['target' => 'Search', 'nth' => null, 'within' => null];

    expect(Verb::check($element('Search…', [0, 0, 10, 10], 'TextField', ['value' => 'Search…']), $at)->code())->toBe("->assertSee('Search…')")
        ->and(Verb::check($element('Email', [0, 0, 10, 10], 'TextField', ['value' => '']), $at)->code())->toBe("->assertSee('Email')");
});

it('picks the control a click records, and says whether it is a field', function () use ($element) {
    $field = $element('Name', [16, 100, 358, 40], 'TextField');
    $driver = new FakeDriver([[$field]]);
    $session = new Session($driver, new Screen($driver, timeoutSeconds: 0.5), '/form');
    $session->state();

    expect($session->pick(50, 120))->toBe(['element' => $field, 'field' => true])
        ->and($session->pick(50, 120, check: true)['field'])->toBeFalse()
        ->and($session->pick(500, 500))->toBe(['element' => null, 'field' => false]);
});

it('leaves no file behind for a screenshot', function () {
    $driver = new FakeDriver([[]]);
    $session = new Session($driver, new Screen($driver), '/');
    $before = glob(sys_get_temp_dir().'/codegen*') ?: [];

    $session->screenshot();

    expect(glob(sys_get_temp_dir().'/codegen*') ?: [])->toBe($before);
});

it('takes away the fakes an interrupted run left in the app', function () {
    $driver = new FakeDriver([[]]);
    $driver->appFiles = ['storage/'.Payload::FAKES => '{}', 'storage/'.Payload::RECORDS => '[]'];
    Sessions::fake($driver);
    Configuration::configure(['scheme' => 'sample']);
    FakeSync::begin();

    try {
        Session::start(new Device('ios', 'iPhone Latest', false), '/');
    } finally {
        Sessions::fake(null);
        Configuration::reset();
    }

    expect($driver->appFiles)->toBe([])
        ->and($driver->opened)->toHaveCount(1);
});
