<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Add;

use Kipchak\Cli\Scaffold\ChangeSet;
use Kipchak\Cli\Scaffold\FileChange;
use Kipchak\Cli\Scaffold\Project;

/**
 * The change set for `kipchak add`: each package's configuration file copied into config/, and its initialise()
 * statement placed in drivers/drivers.php or middlewares/middlewares.php. Reads nothing from disk.
 */
final class AddPackages
{
    public function __construct(private readonly Registrar $registrar = new Registrar()) {}

    /**
     * The files a Project must carry for generate(): the registration files and each package's configuration file.
     *
     * @param list<PackageMetadata> $packages
     * @return list<string>
     */
    public static function filesNeeded(array $packages): array
    {
        $files = ['drivers/drivers.php', 'middlewares/middlewares.php'];
        foreach ($packages as $package) {
            if ($package->configName !== null) {
                $files[] = 'config/' . $package->configName;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param list<PackageMetadata> $packages in dependency order (DependencyResolver)
     * @param array<string, PackageMetadata> $known metadata of every installed Kipchak package
     * @param array<string, string> $samples package name => the content of its sample configuration
     */
    public function generate(Project $project, array $packages, array $known, array $samples): ChangeSet
    {
        $changes = [];
        $messages = [];
        $settings = [];
        $registrationFiles = [];

        foreach ($packages as $package) {
            $file = $package->registrationFile();
            $original = $project->file($file);
            if ($original === null) {
                $messages[] = "{$file} was not found, so {$package->name} was not initialised. Add this line: {$package->initialise}";
                continue;
            }
            $current = $registrationFiles[$file] ?? $original;

            $registration = $this->registrar->register($current, $package, $known);
            array_push($messages, ...$registration->messages);
            if ($registration->content === null) {
                continue;
            }
            $registrationFiles[$file] = $registration->content;

            if ($package->configName !== null) {
                $config = 'config/' . $package->configName;
                if ($project->hasFile($config)) {
                    $messages[] = "{$config} already exists and was kept.";
                } elseif (isset($samples[$package->name])) {
                    $changes[] = FileChange::create($config, $samples[$package->name]);
                    foreach ($this->settingsToSupply($samples[$package->name]) as $key) {
                        $settings[] = ['file' => $config, 'key' => $key];
                    }
                } else {
                    $messages[] = "{$package->name} ships no sample configuration; create {$config} as {$package->docs} describes.";
                }
            }
            $messages[] = "{$package->name}: {$package->docs}";
        }

        foreach ($registrationFiles as $file => $content) {
            $changes[] = FileChange::modify($file, (string) $project->file($file), $content);
        }

        return new ChangeSet($changes, $messages, $settings);
    }

    /**
     * The environment variables a configuration reads with an empty default, which the developer must set: API keys,
     * passwords, tokens. Values with a working default (a host, a port) are not listed.
     *
     * @return list<string>
     */
    private function settingsToSupply(string $config): array
    {
        preg_match_all("/env\\(\\s*'([A-Z0-9_]+)'\\s*,\\s*''\\s*\\)/", $config, $matches);

        return array_values(array_unique($matches[1]));
    }
}
