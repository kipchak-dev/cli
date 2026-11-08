<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use Kipchak\Cli\Project\Composer;
use Kipchak\Cli\Project\InstalledPackages;
use Kipchak\Cli\Project\ProjectReader;
use Kipchak\Cli\Scaffold\Add\AddPackages;
use Kipchak\Cli\Scaffold\Add\DependencyResolver;
use Kipchak\Cli\Scaffold\ChangeSet;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

#[AsCommand(name: 'add', description: 'Install a Kipchak driver or middleware, copy its configuration and initialise it')]
final class AddCommand extends Command
{
    /**
     * Packages that need a dependency Doctrine or another maintainer has not released as stable, with the constraint
     * the project must require beside them. An inline stability flag lets that one package be a development version
     * while the rest of the project stays stable. Remove an entry when its dependency is released: Composer ranks a
     * branch such as 4.0.x-dev above 4.0.0, so the flag would keep projects on the branch.
     *
     * @var array<string, array<string, string>>
     */
    public const array UNRELEASED_DEPENDENCIES = [
        // doctrine/orm 4 is only available as the 4.0.x-dev branch.
        'kipchak/driver-doctrine' => ['doctrine/orm' => '^4@dev'],
    ];

    public function __construct(private readonly Composer $composer = new Composer())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addDryRunOption();
        $this->addArgument('package', InputArgument::REQUIRED, 'doctrine, driver-doctrine or kipchak/driver-doctrine');
        $this->setHelp(
            "Runs composer require, copies the package's sample configuration into config/ (never over an existing\n"
            . "file) and initialises it in drivers/drivers.php or middlewares/middlewares.php, where its documented order\n"
            . "allows. The Kipchak drivers it requires are initialised too, before it. Enterprise packages install with the\n"
            . "project's own Composer credentials. Settings you must supply, such as API keys, are listed afterwards."
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, function () use ($input, $output): int {
            $root = $this->projectRoot($input);
            $name = $this->packageName($root, (string) $input->getArgument('package'));
            $dryRun = (bool) $input->getOption('dry-run');
            $notes = [];

            if (!InstalledPackages::read($root)->isInstalled($name)) {
                if ($dryRun) {
                    $message = "Dry run: {$name} is not installed yet, so its configuration and position cannot be shown "
                        . "until `composer require {$name}` has run. Nothing was written.";
                    $this->isJson($input)
                        ? $this->writeJson($output, ['changes' => [], 'messages' => [$message], 'settings' => [], 'written' => false])
                        : $output->writeln("<comment>{$message}</comment>");

                    return ExitCode::SUCCESS;
                }
                [$alongside, $notes] = $this->unreleasedDependencies($root, $name);
                if ($this->composer->require($root, $name, $alongside) !== 0) {
                    $command = implode(' ', [$name, ...$alongside]);

                    return $this->fail($input, $output, "composer require {$command} failed; nothing else was changed.", ExitCode::FAILURE);
                }
            }

            $installed = InstalledPackages::read($root);
            $packages = new DependencyResolver()->resolve($name, $installed->requires, $installed->metadata);

            $samples = [];
            foreach ($packages as $package) {
                $sample = $installed->sample($package);
                if ($sample !== null) {
                    $samples[$package->name] = $sample;
                }
            }

            $project = new ProjectReader()->read($root, AddPackages::filesNeeded($packages));

            $changeSet = new AddPackages()->generate($project, $packages, $installed->metadata, $samples);

            return $this->applyChangeSet($input, $output, new ChangeSet($changeSet->changes, [...$notes, ...$changeSet->messages], $changeSet->settings));
        });
    }

    /**
     * The requirements to add beside the package for its unreleased dependencies, and a note on each. None when the
     * project already requires the dependency (its own constraint stands) or accepts development versions throughout.
     *
     * @return array{0: list<string>, 1: list<string>} the requirements, as name:constraint, and the notes
     */
    private function unreleasedDependencies(string $root, string $name): array
    {
        $composer = json_decode((string) @file_get_contents($root . '/composer.json'), true);
        $composer = is_array($composer) ? $composer : [];
        if (($composer['minimum-stability'] ?? 'stable') === 'dev') {
            return [[], []];
        }

        $alongside = [];
        $notes = [];
        foreach (self::UNRELEASED_DEPENDENCIES[$name] ?? [] as $dependency => $constraint) {
            if (isset($composer['require'][$dependency]) || isset($composer['require-dev'][$dependency])) {
                continue;
            }
            $alongside[] = "{$dependency}:{$constraint}";
            $notes[] = "{$dependency} " . ltrim((string) strtok($constraint, '@'), '^~') . " is not yet a stable release, so composer.json now requires \"{$dependency}\": \"{$constraint}\": "
                . 'only that package may be a development version.';
        }

        return [$alongside, $notes];
    }

    /**
     * doctrine, driver-doctrine and kipchak/driver-doctrine all name kipchak/driver-doctrine. A short name that is both
     * a driver and a middleware (feature-flags, opentelemetry) must be given in full.
     */
    private function packageName(string $root, string $argument): string
    {
        if (str_contains($argument, '/')) {
            return $argument;
        }
        if (str_starts_with($argument, 'driver-') || str_starts_with($argument, 'middleware-')) {
            return 'kipchak/' . $argument;
        }

        $installed = InstalledPackages::read($root);
        $candidates = array_values(array_filter(
            ["kipchak/driver-{$argument}", "kipchak/middleware-{$argument}"],
            fn (string $candidate): bool => $installed->isInstalled($candidate) || $this->composer->exists($root, $candidate),
        ));

        return match (count($candidates)) {
            1 => $candidates[0],
            0 => throw new UnexpectedValueException("No Kipchak driver or middleware is called {$argument}. The packages are listed on https://kipchak.dev/ecosystem."),
            default => throw new UnexpectedValueException("{$argument} is both a driver and a middleware: use driver-{$argument} or middleware-{$argument}."),
        };
    }
}
