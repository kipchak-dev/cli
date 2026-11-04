<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use Kipchak\Cli\Scaffold\Make\Endpoint;
use Kipchak\Cli\Scaffold\Make\MakeGenerators;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:endpoint', description: 'Add an operation to an existing controller, and its route')]
final class MakeEndpointCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();
        $this->addDryRunOption();
        $this->addArgument('method', InputArgument::REQUIRED, 'GET, POST, PUT, PATCH or DELETE');
        $this->addArgument('path', InputArgument::REQUIRED, 'The whole path, starting with the API version, such as /v1/orders/{id:[0-9]+}/cancel');
        $this->addArgument('handler', InputArgument::REQUIRED, 'Controller::method, such as Orders::cancel');
        $this->addOption('dto', null, InputOption::VALUE_REQUIRED, 'The DTO the operation returns, and takes as its body');
        $this->setHelp(
            "Adds the method, with its OpenAPI attribute, to api/Controllers/<version>/<Controller>.php, and the route to\n"
            . "the version's route file that already routes the controller (routes/<version>/<controller>.php when none\n"
            . "does, created if absent). The method answers 501 Not Implemented until you write it. A route file whose\n"
            . "shape kipchak does not recognise is left alone, and the route to add is printed instead."
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, function () use ($input, $output): int {
            $endpoint = new Endpoint(
                (string) $input->getArgument('method'),
                (string) $input->getArgument('path'),
                (string) $input->getArgument('handler'),
                is_string($input->getOption('dto')) ? $input->getOption('dto') : null,
            );
            $root = $this->projectRoot($input);
            $routeFiles = array_map(
                static fn (string $path): string => substr($path, strlen($root) + 1),
                glob("{$root}/routes/{$endpoint->version}/*.php") ?: [],
            );
            $files = MakeGenerators::endpointFiles($this->project($input), $endpoint, $routeFiles);
            $project = $this->project($input, [...$files, 'tests/OpenApiTest.php']);

            return $this->applyChangeSet($input, $output, new MakeGenerators()->endpoint($project, $endpoint));
        });
    }
}
