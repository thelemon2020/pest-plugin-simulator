<?php

declare(strict_types=1);

namespace Tests\Support;

use NativePhp\Simulator\Socket;

final class ScriptedSocket extends Socket
{
    public bool $listening = false;

    /** @var array<int, bool>|null */
    public ?array $portsListening = null;

    /** @var list<int> */
    public array $ports = [];

    public function reachable(int $port): bool
    {
        $this->ports[] = $port;

        if ($this->portsListening !== null) {
            return $this->portsListening[$port] ?? false;
        }

        return $this->listening;
    }
}
