<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use Kipchak\Cli\Project\Inspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'routes', description: 'List the routes the application registers')]
final class RoutesCommand extends Command
{
    public function __construct(private readonly Inspector $inspector = new Inspector())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp('Boots the application with Kipchak\Core\Api::boot(), in a separate PHP process with the project\'s own autoloader, and lists what its route collector holds.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, function () use ($input, $output): int {
            $routes = $this->inspector->inspect($this->projectRoot($input), document: false)->routes ?? [];

            if ($this->isJson($input)) {
                $this->writeJson($output, ['routes' => $routes]);

                return ExitCode::SUCCESS;
            }

            $width = max([0, ...array_map(static fn (array $route): int => strlen(implode('|', $route['methods'])), $routes)]);
            $patternWidth = max([0, ...array_map(static fn (array $route): int => strlen($route['pattern']), $routes)]);
            foreach ($routes as $route) {
                $output->writeln(rtrim(sprintf("%-{$width}s  %-{$patternWidth}s  %s", implode('|', $route['methods']), $route['pattern'], $route['handler'])));
            }

            return ExitCode::SUCCESS;
        });
    }
}
