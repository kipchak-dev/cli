<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Add;

use Kipchak\Cli\Scaffold\FileChange;
use Kipchak\Cli\Scaffold\Project;

/**
 * What `kipchak add doctrine` writes so that the first migration works with no database server: a SQLite configuration
 * instead of the driver's sample, which is disabled and points at MySQL, and the directories that configuration names.
 */
final class DoctrineStart
{
    public const string PACKAGE = 'kipchak/driver-doctrine';

    public const string CONFIG = 'config/kipchak.doctrine.php';

    private const array DIRECTORIES = ['var/.gitkeep', 'api/Entities/Doctrine/Primary/.gitkeep'];

    private const string IGNORE = '/var/*.sqlite';

    /**
     * @return list<string> the files generate() needs a Project to carry
     */
    public static function filesNeeded(): array
    {
        return [...self::DIRECTORIES, '.gitignore'];
    }

    public static function config(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/templates/config-doctrine-sqlite.php.tpl');
    }

    /**
     * The directories and the ignore line, for a project whose configuration this command is writing.
     *
     * @return list<FileChange>
     */
    public function changes(Project $project): array
    {
        $changes = [];
        foreach (self::DIRECTORIES as $file) {
            if (!$project->hasFile($file)) {
                $changes[] = FileChange::create($file, '');
            }
        }

        $ignore = $project->file('.gitignore');
        if ($ignore === null) {
            $changes[] = FileChange::create('.gitignore', self::IGNORE . "\n");
        } elseif (!in_array(self::IGNORE, array_map(trim(...), explode("\n", $ignore)), true)) {
            $changes[] = FileChange::modify('.gitignore', $ignore, rtrim($ignore, "\n") . "\n" . self::IGNORE . "\n");
        }

        return $changes;
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return [
            'SQLite is the starting default (var/app.sqlite, or SQLITE_PATH), so no database server is needed. Use MySQL or PostgreSQL in production: https://kipchak.dev/docs/driver-doctrine#configuration',
            'Run the first migration with: php vendor/bin/doctrine migrations:up --em=primary',
            'kipchak/driver-memcached was not initialised: Doctrine caches to files. To use Memcached, run kipchak add memcached and set the store in ' . self::CONFIG . '.',
        ];
    }
}
