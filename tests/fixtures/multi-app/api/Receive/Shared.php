<?php

declare(strict_types=1);

namespace Api\Receive;

/**
 * Process-wide flags: set by each app's boot, read by the other's. Separate processes keep them apart.
 */
final class Shared
{
    public static bool $mainBooted = false;

    public static bool $receiveBooted = false;
}
