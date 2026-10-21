<?php

declare(strict_types=1);

namespace NativePhp\Simulator\Exceptions;

/**
 * A database assertion asked for something no pull can answer, such as a column the table
 * does not have. Pulling again would not change that, so it fails the step at once.
 */
final class BadDatabaseQuery extends SimulatorException {}
