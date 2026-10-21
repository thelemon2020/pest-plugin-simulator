<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;
use PDO;

/**
 * Every connection name is the device database, for Models::fromDevice(). A model names
 * the connection it was saved through in the app, which on the host is the test's own.
 *
 * Eloquent gives a model the name of the connection that loaded it. The device has one
 * database, the app's default connection, and the test's counterpart is its own default
 * connection, so that is the name every connection here has. A model read from the record
 * is then a model of the test's default connection, as one the test loaded itself is:
 * Model::is() matches them, and fresh() or a relation loaded later reads the test's
 * database.
 */
final class DeviceConnections implements ConnectionResolverInterface
{
    private ?ConnectionInterface $connection = null;

    public function __construct(private readonly PDO $database, private readonly ?string $default) {}

    public function connection($name = null)
    {
        return $this->connection ??= new SQLiteConnection($this->database, '', '', ['driver' => 'sqlite', 'name' => $this->getDefaultConnection()]);
    }

    public function getDefaultConnection()
    {
        return $this->default ?? 'device';
    }

    public function setDefaultConnection($name) {}
}
