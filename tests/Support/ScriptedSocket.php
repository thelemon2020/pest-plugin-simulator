<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Socket;

final class ScriptedSocket extends Socket
{
    public bool $listening = false;

    /** @var list<int> */
    public array $ports = [];

    public function reachable(int $port): bool
    {
        $this->ports[] = $port;

        return $this->listening;
    }
}
