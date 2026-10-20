<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

mobile(function () {
    it('pays at the express rate a faked API gave, on a faked day', function () {
        Http::fake([
            'api.shipping.test/*' => Http::response(['standard' => 500, 'express' => 1200]),
            'api.stripe.test/*' => Http::response(['status' => 'succeeded']),
        ]);
        Http::preventStrayRequests();
        $this->travelTo('2026-12-24 09:00');
        config(['features.express' => true]);

        screen('/checkout')
            ->assertSee('Express: $12.00', 'Arrives Monday, December 28')
            ->tap('Pay')
            ->assertSee('Paid');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.test/charges'
            && $request['amount'] === 4200);
        Http::assertSentCount(2);
    });

    it('declines when the faked API says so, with a fake set after the screen opened', function () {
        Http::fake(['api.shipping.test/*' => Http::response(['standard' => 500, 'express' => 1200])]);

        $screen = screen('/checkout')->assertSee('Standard: $5.00');

        Http::fake(['api.stripe.test/*' => Http::response(['status' => 'declined'], 402)]);

        $screen->tap('Pay')->assertSee('Declined');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['amount'] === 4200);
    });
});
