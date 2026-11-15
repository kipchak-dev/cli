<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Validate;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    /** Not a defect: something the check did deliberately, reported so that nothing is skipped silently. */
    case Notice = 'notice';
}
