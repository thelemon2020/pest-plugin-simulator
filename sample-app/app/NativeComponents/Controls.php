<?php

namespace App\NativeComponents;

class Controls extends Screen
{
    protected string $title = 'Controls';

    public bool $notifications = false;

    public bool $subscribe = false;

    public int $volume = 20;

    public string $size = 'Medium';

    public string $plan = 'free';

    public function toggleNotifications(): void
    {
        $this->notifications = ! $this->notifications;
    }

    public function toggleSubscribe(): void
    {
        $this->subscribe = ! $this->subscribe;
    }

    public function chooseSize(string $size): void
    {
        $this->size = $size;
    }
}
