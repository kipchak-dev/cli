<?php

declare(strict_types=1);

namespace Kipchak\Cli\Project;

use Kipchak\Cli\Scaffold\ChangeAction;
use Kipchak\Cli\Scaffold\ChangeSet;
use RuntimeException;

/**
 * Writes a change set into a project, all or nothing: it never overwrites a file, and it writes nothing if any file to
 * be created already exists or any file to be modified has changed since the generator read it.
 */
final class ChangeSetWriter
{
    public function apply(ChangeSet $changeSet, string $root): void
    {
        $conflicts = [];
        foreach ($changeSet->changes as $change) {
            $file = $root . '/' . $change->path;
            if ($change->action === ChangeAction::Create && file_exists($file)) {
                $conflicts[] = "{$change->path} already exists.";
            }
            if ($change->action === ChangeAction::Modify && (!is_file($file) || file_get_contents($file) !== $change->original)) {
                $conflicts[] = "{$change->path} has changed since it was read.";
            }
        }
        if ($conflicts !== []) {
            throw new ConflictException($conflicts);
        }

        foreach ($changeSet->changes as $change) {
            $this->write($root . '/' . $change->path, $change->content);
        }
    }

    private function write(string $file, string $content): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        // Write beside the target and rename, so that a file is never left half written.
        $temporary = $file . '.kipchak-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $content) === false || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write {$file}.");
        }
    }
}
