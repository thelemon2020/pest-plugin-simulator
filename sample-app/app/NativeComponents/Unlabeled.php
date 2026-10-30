<?php

namespace App\NativeComponents;

/**
 * An icon button with an a11y-label and a field with neither a label nor a placeholder,
 * so a test can check that assertAccessible() names the field a screen reader cannot.
 */
class Unlabeled extends Screen
{
    protected string $title = 'Unlabeled';

    public string $status = 'Nothing added';

    public string $note = '';

    public function add(): void
    {
        $this->status = 'Added';
    }
}
