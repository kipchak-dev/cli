<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests;

/**
 * A minimal Kipchak project on disk: a composer.json requiring kipchak/core and an installed.json naming its version.
 */
final class ProjectFixture
{
    public static function create(string $coreVersion = '3.16', bool $installed = true): string
    {
        $root = sys_get_temp_dir() . '/kipchak-cli-' . bin2hex(random_bytes(6));
        mkdir($root . '/vendor/composer', 0777, true);
        file_put_contents($root . '/composer.json', json_encode([
            'name' => 'acme/api',
            'require' => ['kipchak/core' => '^3'],
            'autoload' => ['psr-4' => ['Api\\' => 'api/']],
        ]));
        if ($installed) {
            file_put_contents($root . '/vendor/composer/installed.json', json_encode([
                'packages' => [['name' => 'kipchak/core', 'version' => $coreVersion]],
            ]));
        }

        return $root;
    }

    public static function remove(string $root): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
