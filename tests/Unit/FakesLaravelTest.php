<?php

declare(strict_types=1);

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Testing\Fakes\BusFake;
use NativePhp\Simulator\Configuration;
use NativePhp\Simulator\Device;
use NativePhp\Simulator\DeviceDatabase;
use NativePhp\Simulator\Fakes\FakeSync;
use NativePhp\Simulator\Fakes\RemoteEventFake;
use NativePhp\Simulator\Fakes\RemoteMailFake;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Sessions;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\DeviceApp;
use Tests\Support\FakeDriver;
use Tests\Support\LaravelTestCase;
use Tests\Support\Shop\Order;
use Tests\Support\Shop\OrderPaid;
use Tests\Support\Shop\OrderShipped;
use Tests\Support\Shop\Receipt;
use Tests\Support\Shop\ShipOrder;

uses(LaravelTestCase::class);

beforeEach(function () {
    $this->driver = new FakeDriver([[['label' => 'Pay', 'role' => 'Button', 'id' => null, 'center' => [10, 10]]]]);
    Configuration::configure(['scheme' => 'myapp', 'timeout' => 0]);
    Run::useDevice(new Device('ios', 'iPhone 17', true));
    Sessions::fake($this->driver);
    FakeSync::begin();
    $this->device = new DeviceApp($this->driver);

    // Order 2 is paid on the device only. The test's own database has no orders at all.
    $this->device->run(function (): void {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
        });
        Order::query()->insert([['id' => 1, 'status' => 'unpaid'], ['id' => 2, 'status' => 'paid']]);
    });
});

afterEach(function () {
    FakeSync::end($this->driver);
    DeviceDatabase::reset();
    Sessions::fake(null);
    Run::clear();
    Configuration::reset();
    $this->device->remove();
});

it('asserts on the mail the app sent, with its models loaded from the device database', function () {
    Mail::fake();
    FakeSync::sync($this->driver);

    expect(Mail::getFacadeRoot())->toBeInstanceOf(RemoteMailFake::class);

    $this->device->boot();
    $this->device->tap(fn () => Mail::to('ada@example.test')->send(new Receipt(Order::query()->findOrFail(2))));

    Mail::assertSent(Receipt::class, fn (Receipt $mail): bool => $mail->order->id === 2 && $mail->order->status === 'paid' && $mail->hasTo('ada@example.test'));
    Mail::assertSentCount(1);
    Mail::assertNotSent(Receipt::class, fn (Receipt $mail): bool => $mail->order->id === 1);

    expect(fn () => Mail::assertNothingSent())->toThrow(AssertionFailedError::class)
        ->and(Mail::sent(Receipt::class))->toHaveCount(1);
});

it('fakes only the events the test named, and always lets NativePHP\'s own through', function () {
    Event::fake([OrderPaid::class]);
    FakeSync::sync($this->driver);

    expect(Event::getFacadeRoot())->toBeInstanceOf(RemoteEventFake::class);

    $this->device->boot();
    $heard = $this->device->tap(function (): array {
        $heard = [];
        Event::listen('Native\Mobile\Events\Screen\ScreenMounted', function () use (&$heard): void {
            $heard[] = 'native';
        });
        Event::listen('app.other', function () use (&$heard): void {
            $heard[] = 'other';
        });

        event(new OrderPaid(Order::query()->findOrFail(2)));
        event('Native\Mobile\Events\Screen\ScreenMounted');
        event('app.other');

        return $heard;
    });

    expect($heard)->toBe(['native', 'other']);

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event): bool => $event->order->status === 'paid');
    Event::assertNotDispatched('Native\Mobile\Events\Screen\ScreenMounted');
});

it('keeps NativePHP\'s events real when the test fakes every event', function () {
    Event::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();

    $heard = $this->device->tap(function (): bool {
        $heard = false;
        Event::listen('Native\Mobile\Events\Alert\ButtonPressed', function () use (&$heard): void {
            $heard = true;
        });
        event('Native\Mobile\Events\Alert\ButtonPressed');
        event(new OrderPaid(Order::query()->findOrFail(1)));
        // A closure does not serialize, so this one stays out of the record.
        event('app.closure', [fn () => null]);

        return $heard;
    });

    expect($heard)->toBeTrue();

    Event::assertDispatched(OrderPaid::class);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('asserts on the jobs the app queued and dispatched, and the notifications it sent', function () {
    Queue::fake();
    Notification::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();

    $this->device->tap(function (): void {
        Queue::push(new ShipOrder(Order::query()->findOrFail(2)));
        Notification::route('mail', 'ada@example.test')->notify(new OrderShipped(2));
    });

    Queue::assertPushed(ShipOrder::class, fn (ShipOrder $job): bool => $job->order->status === 'paid');
    Queue::assertCount(1);
    Notification::assertSentOnDemand(OrderShipped::class, fn (OrderShipped $notification): bool => $notification->order === 2);
    Notification::assertCount(1);
});

it('asserts on what the app dispatched on the bus', function () {
    Bus::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();

    $this->device->tap(fn () => Bus::dispatch(new ShipOrder(Order::query()->findOrFail(1))));

    Bus::assertDispatched(ShipOrder::class, fn (ShipOrder $job): bool => $job->order->id === 1);
    Bus::assertNotDispatched(ShipOrder::class, fn (ShipOrder $job): bool => $job->order->id === 2);
});

it('puts the app\'s real mailer and dispatcher back when the test ends', function () {
    [$mailer, $events] = $this->device->run(fn (): array => [Mail::getFacadeRoot(), Event::getFacadeRoot()]);

    Mail::fake();
    Event::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();

    expect($this->device->run(fn () => Mail::getFacadeRoot()))->not->toBe($mailer);

    FakeSync::end($this->driver);
    [$after, $afterEvents] = $this->device->tap(fn (): array => [Mail::getFacadeRoot(), Event::getFacadeRoot()]);

    expect($after)->toBe($mailer)
        ->and($afterEvents)->toBe($events);
});

it('starts the record again when the test fakes again part way, as Laravel does', function () {
    Event::fake([OrderPaid::class]);
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => event(new OrderPaid(Order::query()->findOrFail(1))));

    Event::fake([OrderPaid::class, 'app.other']);
    FakeSync::sync($this->driver);
    $this->device->tap(fn () => event('app.other'));

    Event::assertNotDispatched(OrderPaid::class);
    Event::assertDispatched('app.other');
});

it('gives the models it loads the test\'s connection, so Model::is() matches and fresh() reads the test\'s database', function () {
    Mail::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Mail::to('ada@example.test')->send(new Receipt(Order::query()->findOrFail(2))));
    // The same order, in the test's own database, unpaid there.
    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->string('status');
    });
    Order::query()->insert(['id' => 2, 'status' => 'unpaid']);
    $order = Order::query()->findOrFail(2);

    Mail::assertSent(Receipt::class, fn (Receipt $mail): bool => $mail->order->is($order)
        && $mail->order->status === 'paid'
        && $mail->order->fresh()?->status === 'unpaid');
});

it('reads the rest of the record when the app has since deleted one entry\'s model', function () {
    Mail::fake();
    Event::fake([OrderPaid::class]);
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(function (): void {
        Mail::to('ada@example.test')->send(new Receipt(Order::query()->findOrFail(1)));
        event(new OrderPaid(Order::query()->findOrFail(2)));
        Order::query()->whereKey(1)->delete();
    });

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event): bool => $event->order->id === 2);
    // The mail was sent, though its order is gone: it is not taken for none.
    Mail::assertSent(Receipt::class);
    Mail::assertSentCount(1);
    expect(fn () => Mail::assertNothingSent())->toThrow(AssertionFailedError::class);
});

it('sends the app the jobs Queue::fake() and fakeExcept() name', function () {
    Queue::fake([ShipOrder::class]);
    FakeSync::sync($this->driver);

    expect(FakeSync::payload()['queue']['fake'] ?? null)->toBe([ShipOrder::class]);

    Queue::fakeExcept([ShipOrder::class]);
    FakeSync::sync($this->driver);

    expect(FakeSync::payload()['queue']['queue'] ?? null)->toBe([ShipOrder::class]);
});

it('keeps a notification the app sent to a model under that model\'s key', function () {
    Notification::fake();
    FakeSync::sync($this->driver);
    $five = new Order(['id' => 5, 'status' => 'paid']);
    $five->exists = true;
    Notification::send($five, new OrderShipped(5));
    $this->device->boot();
    $this->device->tap(fn () => Notification::send(Order::query()->findOrFail(2), new OrderShipped(2)));

    Notification::assertSentTo($five, OrderShipped::class);
    Notification::assertSentTo(Order::query()->getModel()->newInstance(['id' => 2], true), OrderShipped::class, fn (OrderShipped $sent): bool => $sent->order === 2);
});

it('keeps what the test sent itself after the fake began reading the app', function () {
    Mail::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Mail::to('ada@example.test')->send(new Receipt(Order::query()->findOrFail(2))));
    Mail::assertSentCount(1);

    Mail::to('bob@example.test')->send(new Receipt(new Order(['id' => 9, 'status' => 'paid'])));

    Mail::assertSentCount(2);
    Mail::assertSent(Receipt::class, fn (Receipt $mail): bool => $mail->hasTo('bob@example.test'));
});

it('forgets what the app sent before the test faked mail again', function () {
    Mail::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Mail::to('ada@example.test')->send(new Receipt(Order::query()->findOrFail(2))));
    Mail::assertSentCount(1);

    Mail::fake();
    FakeSync::sync($this->driver);

    Mail::assertNothingSent();

    $this->device->tap(fn () => null);

    Mail::assertNothingSent();
});

it('reads the app\'s record from the readers as well as the asserts', function () {
    Bus::fake();
    Event::fake([OrderPaid::class]);
    Queue::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(function (): void {
        Bus::dispatch(new ShipOrder(Order::query()->findOrFail(2)));
        event(new OrderPaid(Order::query()->findOrFail(2)));
    });

    expect(Bus::hasDispatched(ShipOrder::class))->toBeTrue()
        ->and(Event::hasDispatched(OrderPaid::class))->toBeTrue()
        ->and(Event::dispatchedEvents())->toHaveKey(OrderPaid::class)
        ->and(fn () => Bus::assertNothingPlaced())->toThrow(AssertionFailedError::class);
})->skip(! method_exists(BusFake::class, 'assertNothingPlaced'), 'This Laravel has no assertNothingPlaced().');

it('fills the fake Laravel\'s fake() returned, for a test that kept it', function () {
    $mail = Mail::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => Mail::to('ada@example.test')->send(new Receipt(Order::query()->findOrFail(2))));

    // The facade's assert waits for the app; the object kept from Mail::fake() sees what it pulled.
    Mail::assertSent(Receipt::class);

    expect($mail->sent(Receipt::class))->toHaveCount(1);
    $mail->assertSentCount(1);
});

it('pulls the device database once for a record with many models in it', function () {
    Mail::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(function (): void {
        foreach ([1, 2, 1, 2] as $id) {
            Mail::to('ada@example.test')->send(new Receipt(Order::query()->findOrFail($id)));
        }
    });
    $before = count(array_filter($this->driver->events, fn (array $event): bool => $event === ['pullFile', DeviceDatabase::PATH]));

    Mail::assertSentCount(4);

    $after = count(array_filter($this->driver->events, fn (array $event): bool => $event === ['pullFile', DeviceDatabase::PATH]));

    expect($after - $before)->toBe(1);
});

it('sends the test\'s own cache events to the fake asserts read', function () {
    // The test's cache store exists, so Event::fake() hands it the fake it makes.
    cache()->get('warm');
    Event::fake();
    $this->device->boot();
    FakeSync::sync($this->driver);

    cache()->put('k', 'v');

    Event::assertDispatched(KeyWritten::class);
});

it('fails, and says why, when an assert reads a model the device no longer has', function () {
    Mail::fake();
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(function (): void {
        Mail::to('ada@example.test')->send(new Receipt(Order::query()->findOrFail(1)));
        Order::query()->whereKey(1)->delete();
    });

    expect(fn () => Mail::assertSent(Receipt::class, fn (Receipt $mail): bool => $mail->order->id === 1))
        ->toThrow(AssertionFailedError::class, 'a model it holds is no longer in the device database');
});

it('keeps a notification under its notifiable\'s key when the keys look like a list', function () {
    Notification::fake();
    FakeSync::sync($this->driver);
    $zero = new Order(['id' => 0, 'status' => 'paid']);
    $zero->exists = true;
    Notification::send($zero, new OrderShipped(0));
    $this->device->boot();
    $this->device->tap(function (): void {
        $order = new Order(['id' => 0, 'status' => 'paid']);
        $order->exists = true;
        Notification::send($order, new OrderShipped(10));
    });

    Notification::assertSentToTimes($zero, OrderShipped::class, 2);
});
