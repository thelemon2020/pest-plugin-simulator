<?php

use App\Models\Order;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    Order::query()->delete();
    Order::insert([['id' => 1], ['id' => 2], ['id' => 3]]);
});

/*
 * The same screens the Simulator tests drive on a device, rendered in-process by
 * NativePHP's own harness. A broken screen fails here in a second instead of after a
 * native build.
 */

it('renders every screen', function (string $uri, string $text) {
    Native::visit($uri)->assertSee($text);
})->with([
    ['/', 'Controls'],
    ['/form', 'Hello, nobody'],
    ['/controls', 'Volume: 20'],
    ['/tabs', 'Overview content'],
    ['/orders', 'Unpaid'],
    ['/items', 'Item 1'],
    ['/sheet', 'Sheet closed'],
    ['/alerts', 'Nothing deleted'],
    ['/slow', 'Not started'],
    ['/photos', 'No photo'],
]);

it('saves the form', function () {
    Native::visit('/form')
        ->set('name', 'Ada')
        ->tap('Save')
        ->assertSee('Saved Ada');
});

it('pays one order', function () {
    Native::visit('/orders')
        ->call('pay', 2)
        ->assertSet('paid', [1 => false, 2 => true, 3 => false]);

    expect(Order::find(2)->status)->toBe('paid');
});
