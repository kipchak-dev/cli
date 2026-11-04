<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use Composer\InstalledVersions;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('kipchak', self::version());

        $this->addCommands([
            new ProjectCommand(),
            new NewCommand(),
            new AddCommand(),
        ]);
    }

    private static function version(): string
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('kipchak/cli')
            ? (string) InstalledVersions::getPrettyVersion('kipchak/cli')
            : 'dev';
    }
}
