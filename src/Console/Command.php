<?php

declare(strict_types=1);

namespace Kipchak\Cli\Console;

use JsonException;
use Kipchak\Cli\Project\ChangeSetWriter;
use Kipchak\Cli\Project\ConflictException;
use Kipchak\Cli\Project\ProjectException;
use Kipchak\Cli\Project\ProjectReader;
use Kipchak\Cli\Scaffold\ChangeSet;
use Kipchak\Cli\Scaffold\Project;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The options and output every kipchak command shares: --format, --project-dir and, for commands that write files,
 * --dry-run.
 */
abstract class Command extends SymfonyCommand
{
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
        $this->addOption('project-dir', null, InputOption::VALUE_REQUIRED, 'The project directory (default: the current directory or the nearest parent project)');
    }

    protected function addDryRunOption(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes without writing them');
    }

    protected function isJson(InputInterface $input): bool
    {
        return $input->getOption('format') === 'json';
    }

    protected function projectRoot(InputInterface $input): string
    {
        return new ProjectReader()->findRoot((string) ($input->getOption('project-dir') ?? getcwd()));
    }

    /**
     * @param list<string> $files
     */
    protected function project(InputInterface $input, array $files = []): Project
    {
        return new ProjectReader()->read($this->projectRoot($input), $files);
    }

    /**
     * Runs a command body, turning the tool's exceptions into their exit codes and messages.
     *
     * @param callable(): int $body
     */
    protected function guard(InputInterface $input, OutputInterface $output, callable $body): int
    {
        try {
            return $body();
        } catch (ProjectException $exception) {
            return $this->fail($input, $output, $exception->getMessage(), ExitCode::CANNOT_RUN);
        } catch (ConflictException $exception) {
            return $this->fail($input, $output, $exception->getMessage(), ExitCode::FAILURE, ['conflicts' => $exception->conflicts]);
        } catch (\UnexpectedValueException $exception) {
            // A request the tool understood but cannot satisfy: an unknown package name, a dependency cycle.
            return $this->fail($input, $output, $exception->getMessage(), ExitCode::FAILURE);
        }
    }

    /**
     * Shows a change set and, unless --dry-run is given, writes it.
     */
    protected function applyChangeSet(InputInterface $input, OutputInterface $output, ChangeSet $changeSet): int
    {
        $dryRun = $input->hasOption('dry-run') && (bool) $input->getOption('dry-run');
        if (!$dryRun) {
            new ChangeSetWriter()->apply($changeSet, $this->projectRoot($input));
        }

        if ($this->isJson($input)) {
            $this->writeJson($output, $changeSet->toArray() + ['written' => !$dryRun]);

            return ExitCode::SUCCESS;
        }

        foreach ($changeSet->changes as $change) {
            if ($dryRun) {
                $output->writeln($change->diff());
            } else {
                $output->writeln(sprintf('<info>%s</info> %s', $change->action->value === 'create' ? 'Created' : 'Modified', $change->path));
            }
        }
        foreach ($changeSet->messages as $message) {
            $output->writeln("<comment>{$message}</comment>");
        }
        if ($changeSet->settings !== []) {
            $output->writeln('Set these before using it:');
            foreach ($changeSet->settings as $setting) {
                $output->writeln("  {$setting['file']}: {$setting['key']}");
            }
        }
        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing was written.</comment>');
        }

        return ExitCode::SUCCESS;
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function fail(InputInterface $input, OutputInterface $output, string $message, int $code, array $extra = []): int
    {
        if ($this->isJson($input)) {
            $this->writeJson($output, ['error' => $message] + $extra);
        } else {
            $output->writeln("<error>{$message}</error>");
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function writeJson(OutputInterface $output, array $data): void
    {
        try {
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            $output->writeln('{"error": "The output could not be encoded as JSON."}');
        }
    }
}
