<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Validate;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
