<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Every connection name is the device database, for Models::fromDevice(). A model names
 * the connection it was saved through in the app, which on the host is the test's own.
 */
final class DeviceConnections implements ConnectionResolverInterface
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function connection($name = null)
    {
        return $this->connection;
    }

    public function getDefaultConnection()
    {
        return 'device';
    }

    public function setDefaultConnection($name) {}
}
