<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use InvalidArgumentException;
use Kipchak\Cli\Project\ProjectException;
use Kipchak\Cli\Scaffold\Project;

/**
 * Where the make: commands write, and under which namespace, from the project's PSR-4 map and the API version.
 */
final readonly class Names
{
    public function __construct(
        private Project $project,
        public string $version = 'v1',
    ) {
        if (preg_match('/^v[0-9]+$/', $version) !== 1) {
            throw new InvalidArgumentException("'{$version}' is not an API version. Use v1, v2 and so on.");
        }
    }

    public static function className(string $name, string $what): string
    {
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            throw new InvalidArgumentException("'{$name}' is not a {$what} name. Use a class name in PascalCase, such as Orders.");
        }

        return $name;
    }

    /**
     * The directory of a kind of class, such as 'api/Controllers/v1/'.
     */
    public function directory(string $kind, bool $versioned = true): string
    {
        $api = $this->apiDirectory();

        return $api . $kind . '/' . ($versioned ? $this->version . '/' : '');
    }

    public function namespace(string $kind, bool $versioned = true): string
    {
        $directory = $this->directory($kind, $versioned);
        $namespace = $this->project->namespaceFor($directory);
        if ($namespace === null) {
            throw new ProjectException("{$directory} is not under a PSR-4 namespace in composer.json.");
        }

        return $namespace;
    }

    /**
     * The PSR-4 directory of the API's own code: 'api/' in every Kipchak project.
     */
    private function apiDirectory(): string
    {
        foreach ($this->project->namespaces as $directory) {
            if (trim($directory, '/') === 'api') {
                return 'api/';
            }
        }

        throw new ProjectException('composer.json maps no PSR-4 namespace to api/, where a Kipchak API keeps its code.');
    }
}
