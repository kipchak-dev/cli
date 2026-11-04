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

#[AsCommand(name: 'make:resource', description: 'Write a resource: its DTO, model, controller, routes and tests')]
final class MakeResourceCommand extends MakeCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->fieldsOption();
        $this->addArgument('name', InputArgument::REQUIRED, 'The resource, in the plural, such as Orders');
        $this->addOption('dto', null, InputOption::VALUE_REQUIRED, 'The DTO class (default: the singular of the name)');
        $this->addOption('singular', null, InputOption::VALUE_REQUIRED, 'The singular, when it is not the name without its s');
        $this->addOption('crud', null, InputOption::VALUE_NONE, 'Also create, replace and delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, function () use ($input, $output): int {
            $resource = new Resource(
                (string) $input->getArgument('name'),
                is_string($input->getOption('dto')) ? $input->getOption('dto') : null,
                is_string($input->getOption('singular')) ? $input->getOption('singular') : null,
                (bool) $input->getOption('crud'),
            );
            $project = $this->project($input, ['tests/OpenApiTest.php', 'tests/ApiTestCase.php']);

            return $this->applyChangeSet($input, $output, new MakeGenerators()->resource($project, $resource, $this->fields($input), $this->version($input)));
        });
    }
}
