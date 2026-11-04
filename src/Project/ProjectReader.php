<?php

declare(strict_types=1);

namespace Kipchak\Cli\Project;

use Kipchak\Cli\Scaffold\Project;

/**
 * Builds the generator's description of a project from disk.
 */
final class ProjectReader
{
    /** The kipchak/core major versions this release of the tool has templates for. */
    public const array SUPPORTED_CORE_MAJORS = [3];

    /**
     * Finds the project that contains a directory: the nearest parent whose composer.json requires kipchak/core.
     */
    public function findRoot(string $directory): string
    {
        $directory = realpath($directory) ?: $directory;
        while (true) {
            $composer = $directory . '/composer.json';
            if (is_file($composer) && isset($this->composerJson($composer)['require']['kipchak/core'])) {
                return $directory;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                throw new ProjectException(
                    'This is not a Kipchak project: no composer.json requiring kipchak/core was found in this directory '
                    . 'or above it. Create one with `kipchak new my-api`.'
                );
            }
            $directory = $parent;
        }
    }

    /**
     * @param list<string> $files paths relative to the root whose contents the request needs
     */
    public function read(string $root, array $files = []): Project
    {
        $composer = $this->composerJson($root . '/composer.json');

        $namespaces = [];
        foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $directories) {
            $namespaces[$prefix] = rtrim((string) (is_array($directories) ? $directories[0] : $directories), '/') . '/';
        }

        $project = new Project($namespaces, $this->installedCoreVersion($root), $this->contents($root, $files));
        if (!in_array($project->coreMajor(), self::SUPPORTED_CORE_MAJORS, true)) {
            throw new ProjectException(sprintf(
                'This project uses kipchak/core %s; this release of kipchak/cli supports kipchak/core %s.',
                $project->coreVersion,
                implode(', ', array_map(static fn (int $major): string => "^{$major}", self::SUPPORTED_CORE_MAJORS)),
            ));
        }

        return $project;
    }

    private function installedCoreVersion(string $root): string
    {
        $installed = $root . '/vendor/composer/installed.json';
        if (!is_file($installed)) {
            throw new ProjectException('The dependencies are not installed. Run `composer install` first.');
        }

        $data = json_decode((string) file_get_contents($installed), true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['packages'] ?? $data as $package) {
            if (($package['name'] ?? null) === 'kipchak/core') {
                return (string) $package['version'];
            }
        }

        throw new ProjectException('kipchak/core is required but not installed. Run `composer install` first.');
    }

    /**
     * @param list<string> $files
     * @return array<string, string>
     */
    private function contents(string $root, array $files): array
    {
        $contents = [];
        foreach ($files as $path) {
            $file = $root . '/' . $path;
            if (is_file($file)) {
                $contents[$path] = (string) file_get_contents($file);
            }
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function composerJson(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
