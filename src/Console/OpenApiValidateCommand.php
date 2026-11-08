<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use Kipchak\Cli\Project\Inspector;
use Kipchak\Cli\Scaffold\Validate\Finding;
use Kipchak\Cli\Scaffold\Validate\OpenApiChecks;
use Kipchak\Cli\Scaffold\Validate\Severity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'openapi:validate', description: 'Check the OpenAPI document the API generates, against the specification and the routes')]
final class OpenApiValidateCommand extends Command
{
    public function __construct(private readonly Inspector $inspector = new Inspector())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with 1 for warnings too');
        $this->addOption('no-routes', null, InputOption::VALUE_NONE, 'Check the document only, without booting the application');
        $this->addOption('ignore-route', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'A route the document deliberately leaves out, such as "GET /internal/health" (repeatable)');
        $this->setHelp(
            "Generates the document as the project does (Api\\Utils\\OpenApi::generate() in the starter) and boots the\n"
            . "application to read its routes, in a separate PHP process with the project's own autoloader. Checks:\n\n"
            . "  OAS-001  error    valid against the OpenAPI JSON Schema for its version (3.0 or 3.1)\n"
            . "  OAS-002  error    swagger-php reported no warning while generating it\n"
            . "  OAS-003  error    every \$ref resolves\n"
            . "  KIP-001  error    every route has an operation, and every operation a route\n"
            . "  KIP-002  error    every path parameter of a route is declared on its operation\n"
            . "  KIP-003  error    every operation has a unique operationId, a summary and a tag\n"
            . "  KIP-004  warning  bodies refer to component schemas, apart from the response envelope\n"
            . "  KIP-005  warning  every component schema property has a description and an example\n"
            . "  KIP-006  warning  JSON successes are documented in the response envelope, {code, status, data}\n\n"
            . "The route that serves the document (/openapi.json) is not expected in it. Exit codes: 0 no error, 1 an error\n"
            . "(or a warning, with --strict), 2 the document or the routes could not be produced."
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->guard($input, $output, function () use ($input, $output): int {
            $withRoutes = !$input->getOption('no-routes');
            $inspection = $this->inspector->inspect($this->projectRoot($input), routes: $withRoutes);
            $findings = new OpenApiChecks()->check($inspection, array_values(array_map('strval', (array) $input->getOption('ignore-route'))));

            $errors = count(array_filter($findings, static fn (Finding $f): bool => $f->severity === Severity::Error));
            $warnings = count($findings) - $errors;
            $failed = $errors > 0 || ($input->getOption('strict') && $warnings > 0);

            if ($this->isJson($input)) {
                $this->writeJson($output, [
                    'valid' => !$failed,
                    'errors' => $errors,
                    'warnings' => $warnings,
                    'findings' => array_map(static fn (Finding $f): array => $f->toArray(), $findings),
                ]);
            } else {
                foreach ($findings as $finding) {
                    $tag = $finding->severity === Severity::Error ? 'error' : 'comment';
                    $output->writeln(sprintf('<%s>%s %s</%s> %s: %s', $tag, $finding->check, strtoupper($finding->severity->value), $tag, $finding->location, $finding->message));
                }
                $summary = sprintf('%d %s, %d %s.', $errors, $errors === 1 ? 'error' : 'errors', $warnings, $warnings === 1 ? 'warning' : 'warnings');
                $output->writeln($failed ? "<error>{$summary}</error>" : "<info>The document is valid. {$summary}</info>");
                if (!$withRoutes) {
                    $output->writeln('<comment>Routes were not read (--no-routes), so KIP-001 and KIP-002 were not checked.</comment>');
                }
            }

            return $failed ? ExitCode::FAILURE : ExitCode::SUCCESS;
        });
    }
}
