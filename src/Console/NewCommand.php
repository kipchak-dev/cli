<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use Kipchak\Cli\Project\Composer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'new', description: 'Create a Kipchak API from the starter project')]
final class NewCommand extends Command
{
    public function __construct(private readonly Composer $composer = new Composer())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
        $this->addArgument('directory', InputArgument::REQUIRED, 'The directory to create');
        $this->addOption('starter-version', null, InputOption::VALUE_REQUIRED, 'A kipchak/starter version constraint, such as 4.2');
        $this->setHelp('Runs `composer create-project kipchak/starter <directory>`. A project created this way is identical to one created with Composer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = (string) $input->getArgument('directory');
        if (file_exists($directory) && (!is_dir($directory) || (scandir($directory) ?: []) !== ['.', '..'])) {
            return $this->fail($input, $output, "{$directory} already exists and is not empty.", ExitCode::FAILURE);
        }

        $version = $input->getOption('starter-version');
        if ($this->composer->createProject($directory, is_string($version) ? $version : null) !== 0) {
            return $this->fail($input, $output, 'composer create-project failed.', ExitCode::FAILURE);
        }

        if ($this->isJson($input)) {
            $this->writeJson($output, ['directory' => $directory, 'created' => true]);

            return ExitCode::SUCCESS;
        }

        $output->writeln("<info>Created {$directory}.</info> Next:");
        $output->writeln("  cd {$directory}");
        $output->writeln('  docker compose up --watch      # the API on http://localhost:8080');
        $output->writeln('  vendor/bin/kipchak add <name>  # a driver or middleware, such as doctrine or auth-key');

        return ExitCode::SUCCESS;
    }
}
