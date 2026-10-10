<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Exceptions;

/**
 * A command ran out of time and was stopped. The tool it ran, simctl or adb, may meet the
 * same wait if it is asked again.
 */
final class CommandTimedOut extends SimulatorException {}
