<?php

declare(strict_types=1);

namespace NativePhp\Simulator;

use Closure;
use NativePhp\Simulator\Exceptions\SimulatorException;

/**
 * Off the parallel path, a driver skips its boot path once it has brought its shared device
 * up, until another driver boots a device. This is what catches the device going away
 * anyway, crashed or shut down from outside, between one screen() and the next.
 */
trait RemembersReadiness
{
    /**
     * The driver whose shared device was brought up last. screen() asks for readiness on
     * every call, and the boot path asks simctl or adb about every device each time. Only
     * another driver booting its own device can take this one's down, because booting a
     * named device shuts the others, so until one does there is nothing to ask again.
     */
    private static ?self $ready = null;

    abstract public function ensureReady(): void;

    /**
     * Whether the device this driver brought up is still running.
     */
    abstract private function alive(): bool;

    /**
     * Runs one of screen()'s per-test device commands. When it fails on a device this
     * driver trusted as up, and that device has in fact gone, boots it again, as the boot
     * path did on every screen() before readiness was remembered, and tries once more. Any
     * other failure, such as a timeout or an app that is not installed, goes straight
     * through rather than paying for the boot path and a second attempt.
     *
     * @param  Closure(): void  $command
     */
    private function recovering(Closure $command): void
    {
        try {
            $command();
        } catch (SimulatorException $exception) {
            if (! $this->revive()) {
                throw $exception;
            }

            $command();
        }
    }

    /**
     * Boots the device again when this driver trusted it as up and it has gone since.
     */
    private function revive(): bool
    {
        if (self::$ready !== $this || $this->alive()) {
            return false;
        }

        self::$ready = null;
        $this->ensureReady();

        return true;
    }
}
