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
        $this->setHelp(
            'Boots the application with Kipchak\Core\Api::boot(), in a separate PHP process with the project\'s own autoloader, and lists what its route collector holds.'
            . "\nA project that declares other apps in its composer.json (extra.kipchak.apps) has each booted in a process of its own, and the list then names the app of each route."
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, function () use ($input, $output): int {
            $inspection = $this->inspector->inspect($this->projectRoot($input), document: false);
            $several = $inspection->hasSeveralApps();
            // With one app, the output is as it was before apps could be declared.
            $routes = array_map(static function (array $route) use ($several): array {
                if (!$several) {
                    unset($route['app']);
                }

                return $route;
            }, $inspection->routes ?? []);
            $code = $inspection->appProblems === [] ? ExitCode::SUCCESS : ExitCode::FAILURE;

            if ($this->isJson($input)) {
                $this->writeJson($output, $inspection->appProblems === [] ? ['routes' => $routes] : ['routes' => $routes, 'appProblems' => $inspection->appProblems]);

                return $code;
            }

            $width = max([0, ...array_map(static fn (array $route): int => strlen(implode('|', $route['methods'])), $routes)]);
            $patternWidth = max([0, ...array_map(static fn (array $route): int => strlen($route['pattern']), $routes)]);
            $appWidth = max([0, ...array_map(static fn (array $route): int => strlen($route['app'] ?? ''), $routes)]);
            foreach ($routes as $route) {
                $app = $several ? sprintf("%-{$appWidth}s  ", $route['app'] ?? '') : '';
                $output->writeln(rtrim($app . sprintf("%-{$width}s  %-{$patternWidth}s  %s", implode('|', $route['methods']), $route['pattern'], $route['handler'])));
            }
            foreach ($inspection->appProblems as $problem) {
                $output->writeln("<error>KIP-008 ERROR</error> {$problem['app']}: The app declared in extra.kipchak.apps {$problem['problem']}, so its routes were not read.");
            }

            return $code;
        });
    }
}
