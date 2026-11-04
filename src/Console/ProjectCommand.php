<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project', description: 'Show what kipchak knows about the current project')]
final class ProjectCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, function () use ($input, $output): int {
            $root = $this->projectRoot($input);
            $project = $this->project($input);

            if ($this->isJson($input)) {
                $this->writeJson($output, [
                    'root' => $root,
                    'coreVersion' => $project->coreVersion,
                    'namespaces' => $project->namespaces,
                ]);

                return ExitCode::SUCCESS;
            }

            $output->writeln("Project:      {$root}");
            $output->writeln("kipchak/core: {$project->coreVersion}");
            foreach ($project->namespaces as $prefix => $directory) {
                $output->writeln(sprintf('Namespace:    %s => %s', rtrim($prefix, '\\'), $directory));
            }

            return ExitCode::SUCCESS;
        });
    }
}
