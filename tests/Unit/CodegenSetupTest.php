<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NativePhp\Simulator\Codegen\Models;
use NativePhp\Simulator\Codegen\Session;
use NativePhp\Simulator\Codegen\Setup;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Exceptions\SimulatorException;
use NativePhp\Simulator\Screen;
use Tests\Support\Codegen\Gadget;
use Tests\Support\Codegen\Widget;
use Tests\Support\FakeDriver;
use Tests\Support\LaravelTestCase;

uses(LaravelTestCase::class);

beforeEach(function () {
    Schema::create('gadgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('status')->default('draft');
        $table->unsignedInteger('price')->default(0);
        $table->text('note')->nullable();
        $table->timestamps();
    });

    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('label');
        $table->integer('size');
        $table->timestamps();
        $table->softDeletes();
    });

    $this->directory = dirname(__DIR__).'/Support/Codegen';
});

it('finds the models in a directory, with the columns a test can set', function () {
    $models = Models::in($this->directory, 'Tests\Support\Codegen');

    expect(array_column($models, 'name'))->toBe(['Gadget', 'Widget'])
        ->and(array_column($models[0]['columns'], 'name'))->toBe(['name', 'status', 'price', 'note'])
        ->and($models[0])->toMatchArray(['table' => 'gadgets', 'factory' => false, 'fillable' => true, 'softDeletes' => false])
        ->and(array_column($models[1]['columns'], 'name'))->toBe(['label', 'size'])
        ->and($models[1])->toMatchArray(['factory' => true, 'fillable' => false, 'softDeletes' => true]);
});

it('starts a model with no factory from its column defaults', function () {
    $rows = Models::rows(Models::describe(Gadget::class), 2);

    expect($rows)->toBe([
        ['name' => '', 'status' => 'draft', 'price' => '0', 'note' => null],
        ['name' => '', 'status' => 'draft', 'price' => '0', 'note' => null],
    ]);
});

it('starts a model with a factory from what it makes', function () {
    $rows = Models::rows(Models::describe(Widget::class), 2);

    expect($rows[0]['label'])->toStartWith('Widget ')
        ->and($rows[1]['label'])->not->toBe($rows[0]['label'])
        ->and($rows[0]['size'])->toMatch('/^\d+$/');
});

it('types what the page sent by the columns', function () {
    $values = Models::values(Models::describe(Gadget::class), ['name' => 'Lamp', 'status' => 'live', 'price' => '1200', 'note' => '', 'nope' => 'x']);

    expect($values)->toBe(['name' => 'Lamp', 'status' => 'live', 'price' => 1200, 'note' => null]);
});

it('writes the lines that empty each table and create its rows', function () {
    $setup = new Setup([
        ['model' => Models::describe(Gadget::class), 'rows' => [['name' => 'Lamp', 'status' => 'live', 'price' => 1200, 'note' => null]]],
        ['model' => Models::describe(Widget::class), 'rows' => [['label' => 'Cog', 'size' => 3], ['label' => "It's", 'size' => 4]]],
    ]);

    expect($setup->code())->toBe([
        'uses' => ['use Tests\Support\Codegen\Gadget;', 'use Tests\Support\Codegen\Widget;'],
        'code' => <<<'PHP'
            Widget::withTrashed()->forceDelete();
            Gadget::query()->delete();
            Gadget::create(['name' => 'Lamp', 'status' => 'live', 'price' => 1200, 'note' => null]);
            Widget::forceCreate(['label' => 'Cog', 'size' => 3]);
            Widget::forceCreate(['label' => 'It\'s', 'size' => 4]);
            PHP,
    ])
        ->and((new Setup([]))->code())->toBe(['uses' => [], 'code' => '']);
});

it('puts the rows into a copy of the database for the device, and leaves the real one alone', function () {
    Gadget::create(['name' => 'Old']);
    $driver = new FakeDriver([[]]);

    (new Setup([['model' => Models::describe(Gadget::class), 'rows' => [['name' => 'Lamp', 'status' => 'live', 'price' => 1200, 'note' => null]]]]))->apply($driver);

    $copy = tempnam(sys_get_temp_dir(), 'copy');
    file_put_contents($copy, $driver->databaseContents);
    $rows = (new PDO('sqlite:'.$copy))->query('SELECT name, status, price FROM gadgets')->fetchAll(PDO::FETCH_ASSOC);
    unlink($copy);

    expect($rows)->toBe([['name' => 'Lamp', 'status' => 'live', 'price' => 1200]])
        ->and(Gadget::pluck('name')->all())->toBe(['Old']);
});

it('applies the setup, starts the recording over, and writes the whole test', function () {
    Configuration::configure(['scheme' => 'sample']);
    $driver = new FakeDriver([[]]);
    $session = new Session($driver, new Screen($driver, timeoutSeconds: 0.5), '/gadgets', modelsDirectory: $this->directory, modelsNamespace: 'Tests\Support\Codegen');

    try {
        $session->open('/elsewhere');
        $session->applySetup([['class' => Gadget::class, 'rows' => [['name' => 'Lamp', 'price' => '5']]]]);

        expect($session->script->chain())->toBe("screen('/gadgets');")
            ->and($driver->opened)->toBe(['sample://elsewhere', 'sample://gadgets'])
            ->and($session->file('lists a gadget'))->toBe(<<<'PHP'
                <?php

                use Tests\Support\Codegen\Gadget;

                mobile(function () {
                    it('lists a gadget', function () {
                        Gadget::query()->delete();
                        Gadget::create(['name' => 'Lamp', 'price' => 5]);

                        screen('/gadgets');
                    });
                });

                PHP);
    } finally {
        Configuration::reset();
    }
});

it('only sets up a model the page was offered', function () {
    $driver = new FakeDriver([[]]);
    $session = new Session($driver, new Screen($driver), '/', modelsDirectory: $this->directory, modelsNamespace: 'Tests\Support\Codegen');

    $session->rows('App\Secret', 1);
})->throws(SimulatorException::class, 'is not one of the app\'s models');
