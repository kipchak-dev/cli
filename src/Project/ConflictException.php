<?php

declare(strict_types=1);

namespace Kipchak\Cli\Project;

use RuntimeException;

/**
 * A change set could not be applied, and nothing was written.
 */
final class ConflictException extends RuntimeException
{
    /**
     * @param list<string> $conflicts one sentence per file
     */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct("Nothing was written:\n  " . implode("\n  ", $conflicts));
    }
}
