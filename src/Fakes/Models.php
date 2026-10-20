<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Fakes;

use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use NativePhp\Simulator\DeviceDatabase;
use NativePhp\Simulator\Run;
use NativePhp\Simulator\Sessions;

/**
 * A mailable, an event, or a job that uses SerializesModels travels as the ids of its
 * models, and unserialize() loads them again. The app wrote the record from its own
 * database, so the models are loaded from that one: the device database, pulled with
 * DeviceDatabase. While the record is read, every Eloquent connection is the device's.
 * The test's own connections, and a transaction a test has open on them, are not touched.
 *
 * Each read pulls the database again: the app may have written the row since the last
 * one. A model keeps the name of the connection the app saved it through, so after the
 * read it is a model of the test's connection by that name, and a relation loaded later
 * is loaded there, as Model::is() expects.
 */
final class Models
{
    /**
     * @template T
     *
     * @param  Closure(): T  $unserialize
     * @return T
     */
    public static function fromDevice(string $bytes, Closure $unserialize): mixed
    {
        // Only a record that names a model needs the device's database pulled.
        if (! str_contains($bytes, 'ModelIdentifier') || ! class_exists(Model::class) || ! Run::inside()) {
            return $unserialize();
        }

        $previous = Model::getConnectionResolver();
        DeviceDatabase::forget();
        $database = DeviceDatabase::open(Sessions::get(Run::device()));
        Model::setConnectionResolver(new DeviceConnections($database, $previous instanceof ConnectionResolverInterface ? $previous->getDefaultConnection() : null));

        try {
            return $unserialize();
        } finally {
            if ($previous instanceof ConnectionResolverInterface) {
                Model::setConnectionResolver($previous);
            } else {
                Model::unsetConnectionResolver();
            }
        }
    }
}
