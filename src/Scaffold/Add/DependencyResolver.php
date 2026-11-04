<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Add;

use UnexpectedValueException;

/**
 * Orders a package and the Kipchak drivers it requires, dependencies first (SR-2026-012 §3.3.6, 1.3.1: "the package"
 * means the package and the Kipchak drivers it requires). Only packages composer.json requires are followed, never
 * suggested ones.
 */
final class DependencyResolver
{
    /**
     * @param array<string, list<string>> $requires package name => the Kipchak packages its composer.json requires
     * @param array<string, PackageMetadata> $known metadata of every installed Kipchak package, by name
     * @return list<PackageMetadata> the package and every Kipchak driver it requires, each after its dependencies
     */
    public function resolve(string $package, array $requires, array $known): array
    {
        $ordered = [];
        $this->visit($package, $requires, $known, [], $ordered);

        return array_values($ordered);
    }

    /**
     * @param array<string, list<string>> $requires
     * @param array<string, PackageMetadata> $known
     * @param list<string> $path
     * @param array<string, PackageMetadata> $ordered
     */
    private function visit(string $name, array $requires, array $known, array $path, array &$ordered): void
    {
        if (isset($ordered[$name])) {
            return;
        }
        if (in_array($name, $path, true)) {
            throw new UnexpectedValueException(
                'These packages require each other, so kipchak cannot order them: ' . implode(' -> ', [...$path, $name]) . '.'
            );
        }
        $metadata = $known[$name] ?? throw new UnexpectedValueException("{$name} declares no Kipchak metadata (extra.kipchak).");

        foreach ($requires[$name] ?? [] as $dependency) {
            // Only Kipchak drivers are initialised on a package's behalf; a required middleware or library is not.
            if (isset($known[$dependency]) && !$known[$dependency]->isMiddleware()) {
                $this->visit($dependency, $requires, $known, [...$path, $name], $ordered);
            }
        }

        $ordered[$name] = $metadata;
    }
}
