<?php

declare(strict_types=1);

namespace Kipchak\Cli\Project;

use Kipchak\Cli\Scaffold\Add\PackageMetadata;

/**
 * The Kipchak packages installed in a project, from vendor/composer/installed.json: their metadata, what each
 * requires, and the sample configuration each ships.
 */
final class InstalledPackages
{
    /** @var array<string, PackageMetadata> */
    public readonly array $metadata;

    /** @var array<string, list<string>> */
    public readonly array $requires;

    /**
     * @param array<string, string> $installPaths package name => its directory
     */
    private function __construct(array $metadata, array $requires, private readonly array $installPaths)
    {
        $this->metadata = $metadata;
        $this->requires = $requires;
    }

    public static function read(string $root): self
    {
        $file = $root . '/vendor/composer/installed.json';
        if (!is_file($file)) {
            throw new ProjectException('The dependencies are not installed. Run `composer install` first.');
        }
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        $metadata = [];
        $requires = [];
        $paths = [];
        foreach ($data['packages'] ?? $data as $package) {
            $name = (string) ($package['name'] ?? '');
            if (!str_starts_with($name, 'kipchak/')) {
                continue;
            }
            $paths[$name] = $root . '/vendor/' . $name;
            $requires[$name] = array_values(array_filter(
                array_keys($package['require'] ?? []),
                static fn (string $dependency): bool => str_starts_with($dependency, 'kipchak/'),
            ));
            $meta = PackageMetadata::fromComposer($package);
            if ($meta !== null) {
                $metadata[$name] = $meta;
            }
        }

        return new self($metadata, $requires, $paths);
    }

    public function isInstalled(string $name): bool
    {
        return isset($this->installPaths[$name]);
    }

    public function sample(PackageMetadata $package): ?string
    {
        if ($package->config === null || !isset($this->installPaths[$package->name])) {
            return null;
        }
        $file = $this->installPaths[$package->name] . '/' . $package->config;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }
}
