<?php

declare(strict_types=1);

namespace Kipchak\Cli\Project;

/**
 * Runs Composer for the commands that install something (`new`, `add`). Composer's own output goes to standard
 * error, so that --format=json output on standard output stays parseable.
 */
class Composer
{
    public function require(string $root, string $package): int
    {
        return $this->run(['require', $package], $root);
    }

    public function createProject(string $directory, ?string $version): int
    {
        return $this->run(array_values(array_filter(
            ['create-project', 'kipchak/starter', $directory, $version, '--no-interaction'],
            static fn (?string $argument): bool => $argument !== null,
        )), null);
    }

    /**
     * Whether a package exists in the repositories this project uses (Packagist, and the Enterprise repository when
     * the project is configured for it).
     */
    public function exists(string $root, string $package): bool
    {
        return $this->run(['show', '--all', '--quiet', $package], $root, quiet: true) === 0;
    }

    /**
     * @param list<string> $arguments
     */
    protected function run(array $arguments, ?string $workingDirectory, bool $quiet = false): int
    {
        $binary = getenv('COMPOSER_BINARY') ?: 'composer';
        $stderr = $quiet ? ['file', '/dev/null', 'w'] : STDERR;
        $process = proc_open([$binary, ...$arguments], [0 => STDIN, 1 => $stderr, 2 => $stderr], $pipes, $workingDirectory);
        if (!is_resource($process)) {
            throw new ProjectException('Composer could not be started. Install it from https://getcomposer.org.');
        }

        return proc_close($process);
    }
}
