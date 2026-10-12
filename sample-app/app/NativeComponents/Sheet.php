<?php

namespace App\NativeComponents;

class Sheet extends Screen
{
    protected string $title = 'Sheet';

    public bool $open = false;

    public string $status = 'Sheet closed';

    public function show(): void
    {
        $this->open = true;
        $this->status = 'Sheet open';
    }

    public function choose(): void
    {
        $this->open = false;
        $this->status = 'Chose from the sheet';
    }

    public function close(): void
    {
        $this->open = false;
        $this->status = 'Sheet dismissed';
    }
}
