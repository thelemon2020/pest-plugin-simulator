<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

class Socket
{
    public function reachable(int $port): bool
    {
        $errno = 0;
        $error = '';
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
