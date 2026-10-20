<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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

it('keeps what the app recorded when the test changes a fake part way', function () {
    Event::fake([OrderPaid::class]);
    FakeSync::sync($this->driver);
    $this->device->boot();
    $this->device->tap(fn () => event(new OrderPaid(Order::query()->findOrFail(1))));

    Event::fake([OrderPaid::class, 'app.other']);
    FakeSync::sync($this->driver);
    $this->device->tap(fn () => event('app.other'));

    Event::assertDispatched(OrderPaid::class);
    Event::assertDispatched('app.other');
});
