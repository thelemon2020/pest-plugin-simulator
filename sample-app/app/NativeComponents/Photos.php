<?php

namespace App\NativeComponents;

use Native\Mobile\Attributes\On;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;

class Photos extends Screen
{
    protected string $title = 'Photos';

    public string $status = 'No photo';

    public function pick(): void
    {
        Camera::pickImages('image');
    }

    #[On(MediaSelected::class)]
    public function picked(bool $success, int $count = 0, bool $cancelled = false): void
    {
        $this->status = match (true) {
            $cancelled => 'Picking cancelled',
            $success => $count === 1 ? 'Picked 1 photo' : "Picked {$count} photos",
            default => 'Picking failed',
        };
    }
}
