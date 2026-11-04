<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

final class UnifiedDiff
{
    public static function between(string $path, string $from, string $to, ChangeAction $action): string
    {
        $header = $action === ChangeAction::Create
            ? "--- /dev/null\n+++ b/{$path}\n"
            : "--- a/{$path}\n+++ b/{$path}\n";

        return (new Differ(new UnifiedDiffOutputBuilder($header)))->diff($from, $to);
    }
}
