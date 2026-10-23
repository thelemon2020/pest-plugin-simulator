<?php

use App\Events\OrderPaid;
use App\Mail\Receipt;
use App\Models\Order;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Order::query()->delete();
    Order::insert([['id' => 1, 'total' => 1200], ['id' => 2, 'total' => 4200], ['id' => 3, 'total' => 900]]);
});

mobile(function () {
    it('pays the second of three orders', function () {
        screen('/orders')
            ->nth(2)->tap('Pay')
            ->assertSee('Paid')
            ->assertDatabaseHas('orders', ['id' => 2, 'status' => 'paid'])
            ->assertDatabaseHas(Order::findOrFail(2), ['status' => 'paid'])
            ->assertModelExists(Order::findOrFail(3))
            ->assertDatabaseMissing(Order::class, ['id' => 1, 'status' => 'paid'])
            ->assertDatabaseCount('orders', 3);

        expect(deviceDatabase()->query('SELECT paid_at FROM orders WHERE id = 2')->fetchColumn())->not->toBeNull();
    });

    it('mails a receipt for the order it paid, and says it was paid', function () {
        Mail::fake();
        Event::fake([OrderPaid::class]);

        screen('/orders')
            ->nth(2)->tap('Pay')
            ->assertSee('Paid');

        // The test's own database still has order 2 unpaid. The order in the mail is the
        // one the app paid, loaded from the app's database.
        Mail::assertSent(Receipt::class, fn (Receipt $mail): bool => $mail->order->id === 2
            && $mail->order->status === 'paid'
            && $mail->hasTo('customer@example.test'));
        Mail::assertSentCount(1);
        Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event): bool => $event->order->id === 2);
    });

    it('saves the receipt to the disk the test faked', function () {
        Storage::fake('local');

        screen('/orders')
            ->nth(2)->tap('Pay')
            ->assertSee('Paid');

        Storage::disk('local')->assertExists('receipts/2.txt', 'Order 2: 4200');
        Storage::disk('local')->assertMissing('receipts/1.txt');
    });

    it('ships an order in a batch', function () {
        Bus::fake();

        screen('/orders')->nth(3)->tap('Ship');

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === 'ship-3' && $batch->jobs->first()->order->id === 3);
        Bus::assertBatchCount(1);
    });
});
