<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an order is paid. It carries the order as a model, so a test that asserts on it
 * gets the order the app paid, from the app's own database.
 */
class Receipt extends Mailable
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
