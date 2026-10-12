<?php

namespace App\NativeComponents;

class Form extends Screen
{
    protected string $title = 'Form';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $notes = '';

    public string $status = '';

    public function save(): void
    {
        $this->status = $this->name === '' ? 'Name is required' : "Saved {$this->name}";
    }

    public function search(string $value): void
    {
        $this->status = "Searched {$value}";
    }
}
