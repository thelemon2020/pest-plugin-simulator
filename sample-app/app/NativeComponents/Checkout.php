<?php

namespace App\NativeComponents;

use Illuminate\Support\Facades\Http;

/**
 * Talks to two APIs and reads the clock and a feature flag, so a test can fake all four the
 * way it would in Laravel: Http::fake(), travelTo(), and config().
 */
class Checkout extends Screen
{
    protected string $title = 'Checkout';

    public string $rate = '';

    public string $arrives = '';

    public string $status = 'Not paid';

    public function mount(): void
    {
        $rates = Http::get('https://api.shipping.test/rates')->json();
        $express = (bool) config('features.express');
        $cents = (int) ($express ? $rates['express'] : $rates['standard']);

        $this->rate = ($express ? 'Express' : 'Standard').': $'.number_format($cents / 100, 2);
        $this->arrives = 'Arrives '.now()->addWeekdays($express ? 2 : 5)->format('l, F j');
    }

    public function pay(): void
    {
        $charge = Http::post('https://api.stripe.test/charges', ['amount' => 4200]);

        $this->status = $charge->json('status') === 'succeeded' ? 'Paid' : 'Declined';
    }
}
