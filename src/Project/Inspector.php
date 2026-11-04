<?php

declare(strict_types=1);

namespace Kipchak\Cli\Project;

use Kipchak\Cli\Scaffold\Validate\Inspection;
use UnexpectedValueException;

/**
 * Runs resources/inspect.php in a separate PHP process with the project's own autoloader (SR-2026-012 §3.3.5), so
 * that the project's versions of swagger-php, Slim and its drivers are used and nothing of the tool's is loaded.
 */
class Inspector
{
    private const string SCRIPT = __DIR__ . '/../../resources/inspect.php';

    /**
     * @throws ProjectException when the document or the routes cannot be produced
     */
    public function inspect(string $root, bool $document = true, bool $routes = true): Inspection
    {
        $command = [PHP_BINARY, self::SCRIPT, $root];
        if (!$document) {
            $command[] = '--no-document';
        }
        if (!$routes) {
            $command[] = '--no-routes';
        }

        [$code, $stdout, $stderr] = $this->run($command, $root);
        try {
            $inspection = Inspection::fromJson($stdout);
        } catch (UnexpectedValueException $exception) {
            $detail = trim($stderr) === '' ? '' : "\n" . trim($stderr);
            throw new ProjectException('The project could not be inspected: ' . $exception->getMessage() . $detail);
        }
        if ($code !== 0) {
            throw new ProjectException("The project could not be inspected (exit code {$code}).");
        }

        return $inspection;
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string} exit code, standard output, standard error
     */
    protected function run(array $command, string $workingDirectory): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory);
        if (!is_resource($process)) {
            throw new ProjectException('PHP could not be started to inspect the project.');
        }
        // Both pipes are read as data arrives, so that a project printing a lot while it boots cannot fill one pipe
        // and stop the process.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        do {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $chunk = (string) fread($pipe, 65536);
                $pipe === $pipes[1] ? $stdout .= $chunk : $stderr .= $chunk;
            }
        } while (!feof($pipes[1]) || !feof($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
