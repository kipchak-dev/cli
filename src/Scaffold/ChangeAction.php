<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold;

enum ChangeAction: string
{
    case Create = 'create';
    case Modify = 'modify';
}
