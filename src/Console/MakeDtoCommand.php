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

#[AsCommand(name: 'make:dto', description: 'Write a DTO with its OpenAPI schema attributes')]
final class MakeDtoCommand extends MakeCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->fieldsOption();
        $this->addArgument('name', InputArgument::REQUIRED, 'The DTO class, such as Order');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, function () use ($input, $output): int {
            $name = (string) $input->getArgument('name');
            $resource = new Resource($name . 's', dto: $name, singular: $name);

            return $this->applyChangeSet($input, $output, new MakeGenerators()->dto($this->project($input), $resource, $this->fields($input), $this->version($input)));
        });
    }
}
