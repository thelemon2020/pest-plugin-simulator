<?php

declare(strict_types=1);

namespace Tests\Support\Shop;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class Receipt extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Receipt for order {$this->order->id}");
    }

    public function content(): Content
    {
        return new Content(htmlString: "Order {$this->order->id} is {$this->order->status}.");
    }
}
