<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use NativePhp\Simulator\Exceptions\SimulatorException;

final class Configuration
{
    private static ?string $scheme = null;

    private static ?string $host = null;

    private static ?string $bundleId = null;

    private static float $timeout = 15.0;

    private static ?string $appDirectory = null;

    /**
     * @param  array{scheme?: string, host?: string, bundle_id?: string, timeout?: float, app_directory?: string}  $values
     */
    public static function configure(array $values): void
    {
        if (isset($values['scheme'])) {
            self::$scheme = $values['scheme'];
        }

        if (isset($values['host'])) {
            self::$host = $values['host'];
        }

        if (isset($values['bundle_id'])) {
            self::$bundleId = $values['bundle_id'];
        }

        if (isset($values['timeout'])) {
            self::$timeout = $values['timeout'];
        }

        if (isset($values['app_directory'])) {
            self::$appDirectory = $values['app_directory'];
        }
    }

    public static function reset(): void
    {
        self::$scheme = null;
        self::$host = null;
        self::$bundleId = null;
        self::$timeout = 15.0;
        self::$appDirectory = null;
    }

    public static function resolve(): self
    {
        return new self(
            resolvedScheme: self::present(self::$scheme ?? self::env('NATIVEPHP_DEEPLINK_SCHEME') ?? self::laravel('deeplink_scheme')),
            resolvedHost: self::present(self::$host ?? self::env('NATIVEPHP_DEEPLINK_HOST') ?? self::laravel('deeplink_host')),
            resolvedBundleId: self::present(self::$bundleId ?? self::env('NATIVEPHP_APP_ID') ?? self::laravel('app_id')),
            resolvedTimeout: self::$timeout,
            resolvedAppDirectory: self::$appDirectory ?? getcwd(),
        );
    }

    private function __construct(
        private readonly ?string $resolvedScheme,
        private readonly ?string $resolvedHost,
        private readonly ?string $resolvedBundleId,
        private readonly float $resolvedTimeout,
        private readonly string $resolvedAppDirectory,
    ) {}

    public function scheme(): string
    {
        return $this->resolvedScheme ?? throw new SimulatorException(
            'Set NATIVEPHP_DEEPLINK_SCHEME, or call Configuration::configure([\'scheme\' => \'myapp\']).',
        );
    }

    public function deeplinkScheme(): ?string
    {
        return $this->resolvedScheme;
    }

    public function deeplinkHost(): ?string
    {
        return $this->resolvedHost;
    }

    public function bundleId(): string
    {
        return $this->resolvedBundleId ?? throw new SimulatorException(
            'Set NATIVEPHP_APP_ID, or call Configuration::configure([\'bundle_id\' => \'com.example.app\']).',
        );
    }

    public function appId(): ?string
    {
        return $this->resolvedBundleId;
    }

    public function timeout(): float
    {
        return $this->resolvedTimeout;
    }

    public function appDirectory(): string
    {
        return $this->resolvedAppDirectory;
    }

    public function urlFor(string $path): string
    {
        if (str_contains($path, '://')) {
            return $path;
        }

        $path = ltrim($path, '/');

        if ($this->resolvedScheme !== null) {
            return $this->resolvedScheme.'://'.$path;
        }

        if ($this->resolvedHost !== null) {
            return 'https://'.$this->resolvedHost.'/'.$path;
        }

        throw new SimulatorException(
            'Set NATIVEPHP_DEEPLINK_SCHEME or NATIVEPHP_DEEPLINK_HOST, or call Configuration::configure([\'scheme\' => \'myapp\']).',
        );
    }

    private static function present(?string $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function laravel(string $key): ?string
    {
        if (! function_exists('config')) {
            return null;
        }

        $value = config('nativephp.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
