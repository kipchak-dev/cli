<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use Kipchak\Cli\Scaffold\Make\MakeGenerators;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:middleware', description: 'Write a PSR-15 middleware in api/Middlewares')]
final class MakeMiddlewareCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();
        $this->addDryRunOption();
        $this->addArgument('name', InputArgument::REQUIRED, 'The middleware class, such as RequireRole');
        $this->addOption('global', null, InputOption::VALUE_NONE, 'Also add it for every request in middlewares/middlewares.php, between the two Error lines');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, fn (): int => $this->applyChangeSet(
            $input,
            $output,
            new MakeGenerators()->middleware(
                $this->project($input, ['middlewares/middlewares.php']),
                (string) $input->getArgument('name'),
                (bool) $input->getOption('global'),
            ),
        ));
    }
}
