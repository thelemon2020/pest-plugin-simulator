<?php

declare(strict_types=1);

namespace Tests\Support\Shop;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrderShipped extends Notification
{
    use Queueable;

    public function __construct(public int $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->line("Order {$this->order} shipped.");
    }
}
