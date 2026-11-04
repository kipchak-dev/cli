<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use Kipchak\Cli\Scaffold\Make\Field;
use Kipchak\Cli\Scaffold\Make\MakeGenerators;
use Kipchak\Cli\Scaffold\Make\Resource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:controller', description: 'Write an empty controller, ready for its operations')]
final class MakeControllerCommand extends MakeCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'The controller class, such as Orders');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, fn (): int => $this->applyChangeSet(
            $input,
            $output,
            new MakeGenerators()->controller($this->project($input), (string) $input->getArgument('name'), $this->version($input)),
        ));
    }
}
