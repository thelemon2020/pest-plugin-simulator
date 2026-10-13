<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

/**
 * The APNs payload push() delivers.
 */
final class PushNotification
{
    /**
     * @param  array<string, mixed>  $data  custom keys the app reads beside `aps`
     * @return array<string, mixed>
     */
    public static function payload(string $title, string $body = '', array $data = []): array
    {
        $alert = $body === '' ? ['title' => $title] : ['title' => $title, 'body' => $body];

        return ['aps' => ['alert' => $alert, 'sound' => 'default']] + $data;
    }
}
