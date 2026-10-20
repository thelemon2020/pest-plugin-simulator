<?php

use App\Models\Order;

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
            ->assertDatabaseMissing(Order::class, ['id' => 1, 'status' => 'paid'])
            ->assertDatabaseCount('orders', 3);

        expect(deviceDatabase()->query('SELECT paid_at FROM orders WHERE id = 2')->fetchColumn())->not->toBeNull();
    });
});
