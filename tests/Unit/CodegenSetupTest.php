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

it('applies the setup, starts the recording over, and shows the lines it runs first', function () {
    Configuration::configure(['scheme' => 'sample']);
    $driver = new FakeDriver([[]]);
    $session = new Session($driver, new Screen($driver, timeoutSeconds: 0.5), '/gadgets', modelsDirectory: $this->directory, modelsNamespace: 'Tests\Support\Codegen');

    try {
        $session->open('/elsewhere');
        $session->applySetup([['class' => Gadget::class, 'rows' => [['name' => 'Lamp', 'price' => '5']]]]);

        expect($session->script->chain())->toBe("screen('/gadgets');")
            ->and($driver->opened)->toBe(['sample://elsewhere', 'sample://gadgets'])
            ->and($session->state()['setupCode'])->toBe(<<<'PHP'
                use Tests\Support\Codegen\Gadget;

                Gadget::query()->delete();
                Gadget::create(['name' => 'Lamp', 'price' => 5]);
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

it('adds a field the model did not list, typed by how its values look', function () {
    $model = Models::withFields(Models::describe(Gadget::class), ['created_at', 'name']);

    expect(array_column($model['columns'], 'name'))->toBe(['name', 'status', 'price', 'note', 'created_at'])
        ->and($model['fillable'])->toBeFalse()
        ->and(Models::values($model, ['name' => 'Lamp', 'created_at' => '2026-10-27 09:00:00']))->toBe(['name' => 'Lamp', 'created_at' => '2026-10-27 09:00:00'])
        ->and(Models::values($model, ['name' => 'Lamp', 'created_at' => '']))->toBe(['name' => 'Lamp']);
});

it('refuses a field name that is not a column name', function () {
    Models::withFields(Models::describe(Gadget::class), ['name; DROP TABLE gadgets']);
})->throws(SimulatorException::class, 'is not a column name');

it('creates an added field with forceCreate, since the model would not fill it', function () {
    $driver = new FakeDriver([[]]);
    $session = new Session($driver, new Screen($driver, timeoutSeconds: 0.5), '/gadgets', modelsDirectory: $this->directory, modelsNamespace: 'Tests\Support\Codegen');
    Configuration::configure(['scheme' => 'sample']);

    try {
        $session->applySetup([['class' => Gadget::class, 'fields' => ['created_at'], 'rows' => [['name' => 'Lamp', 'created_at' => '2026-10-27 09:00:00']]]]);
    } finally {
        Configuration::reset();
    }

    expect($session->state()['setupCode'])->toContain("Gadget::forceCreate(['name' => 'Lamp', 'created_at' => '2026-10-27 09:00:00']);");
});

it('writes rows made by the factory, pinning what was filled in', function () {
    $setup = new Setup([[
        'model' => Models::describe(Widget::class),
        'factory' => true,
        'rows' => [['label' => 'Cog'], [], [], ['size' => 9], []],
    ]]);

    expect($setup->code()['code'])->toBe(<<<'PHP'
        Widget::withTrashed()->forceDelete();
        Widget::factory()->create(['label' => 'Cog']);
        Widget::factory()->count(2)->create();
        Widget::factory()->create(['size' => 9]);
        Widget::factory()->create();
        PHP);
});

it('leaves an empty value to the factory', function () {
    expect(Models::values(Models::describe(Widget::class), ['label' => '', 'size' => '4'], factory: true))->toBe(['size' => 4]);
});

it('makes the rows through the factory when it applies them', function () {
    Configuration::configure(['scheme' => 'sample']);
    $driver = new FakeDriver([[]]);
    $session = new Session($driver, new Screen($driver, timeoutSeconds: 0.5), '/widgets', modelsDirectory: $this->directory, modelsNamespace: 'Tests\Support\Codegen');

    try {
        $session->applySetup([['class' => Widget::class, 'factory' => true, 'rows' => [['label' => 'Cog', 'size' => ''], ['label' => '', 'size' => '']]]]);
    } finally {
        Configuration::reset();
    }

    $copy = tempnam(sys_get_temp_dir(), 'copy');
    file_put_contents($copy, $driver->databaseContents);
    $rows = (new PDO('sqlite:'.$copy))->query('SELECT label, size FROM widgets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    unlink($copy);

    expect($rows[0]['label'])->toBe('Cog')
        ->and($rows[0]['size'])->toBeGreaterThan(0)
        ->and($rows[1]['label'])->toStartWith('Widget ')
        ->and($session->state()['setupCode'])->toContain("Widget::factory()->create(['label' => 'Cog']);\nWidget::factory()->create();");
});

it('refuses the factory for a model that has none', function () {
    $driver = new FakeDriver([[]]);
    $session = new Session($driver, new Screen($driver), '/', modelsDirectory: $this->directory, modelsNamespace: 'Tests\Support\Codegen');

    $session->applySetup([['class' => Gadget::class, 'factory' => true, 'rows' => [['name' => 'Lamp']]]]);
})->throws(SimulatorException::class, 'Gadget has no factory to use.');
