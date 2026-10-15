<?php

namespace App\NativeComponents;

use Native\Mobile\Facades\Dialog;

class Alerts extends Screen
{
    protected string $title = 'Alerts';

    public string $status = 'Nothing deleted';

    public function confirm(): void
    {
        Dialog::alert('Delete everything?', 'This cannot be undone.', [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Delete', 'style' => 'destructive'],
        ])->buttonPressed(function ($event): void {
            $this->status = $event->label === 'Delete' ? 'Deleted' : 'Kept';
        });
    }
}
