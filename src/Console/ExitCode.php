<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

/**
 * Exit codes are part of the tool's interface (SR-2026-012 §3.4).
 */
final class ExitCode
{
    /** The command did what was asked. */
    public const int SUCCESS = 0;

    /** The command ran and found a problem: a conflict, an invalid argument, a failed check. */
    public const int FAILURE = 1;

    /** The command could not run: not a Kipchak project, dependencies missing, unsupported core version. */
    public const int CANNOT_RUN = 2;
}
