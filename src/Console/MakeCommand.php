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

/**
 * The options make:dto, make:controller and make:resource share.
 */
abstract class MakeCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();
        $this->addDryRunOption();
        $this->addOption('api-version', null, InputOption::VALUE_REQUIRED, 'The API version folder', 'v1');
    }

    protected function fieldsOption(): void
    {
        $this->addOption('fields', null, InputOption::VALUE_REQUIRED,
            'Comma-separated name:type, where type is int, float, string, bool, datetime, a DTO name or Name[]; ?type is nullable. Example: id:int,reference:string,note:?string');
    }

    /**
     * @return list<Field>
     */
    protected function fields(InputInterface $input): array
    {
        return Field::parseList((string) ($input->getOption('fields') ?? ''));
    }

    protected function version(InputInterface $input): string
    {
        return (string) $input->getOption('api-version');
    }
}
