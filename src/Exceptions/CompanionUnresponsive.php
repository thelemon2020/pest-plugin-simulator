<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Exceptions;

/**
 * idb_companion did not answer in time, or nothing took the connection. Whatever is sent
 * to it next meets the same silence.
 */
final class CompanionUnresponsive extends SimulatorException {}
