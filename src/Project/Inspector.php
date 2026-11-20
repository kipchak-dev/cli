<?php

declare(strict_types=1);

namespace Kipchak\Cli\Project;

use Kipchak\Cli\Scaffold\Validate\Inspection;
use UnexpectedValueException;

/**
 * Runs resources/inspect.php in a separate PHP process with the project's own autoloader (SR-2026-012 §3.3.5), so
 * that the project's versions of swagger-php, Slim and its drivers are used and nothing of the tool's is loaded.
 *
 * When the project declares other apps under extra.kipchak.apps, each is booted in a process of its own after the
 * main one, so that one app's configuration, container or globals cannot affect another's routes. Every process is
 * stopped at the time limit, so that a boot that hangs cannot hang the command.
 */
class Inspector
{
    private const string SCRIPT = __DIR__ . '/../../resources/inspect.php';

    /**
     * @param int $timeout the seconds each inspection process may run before it is stopped
     */
    public function __construct(private readonly int $timeout = 300) {}

    /**
     * @throws ProjectException when the document or the main app's routes cannot be produced
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

        [$code, $stdout, $stderr, $stopped] = $this->run($command, $root);
        if ($stopped) {
            throw new ProjectException("The project could not be inspected: the inspection was stopped after {$this->timeout} seconds.");
        }
        try {
            $inspection = Inspection::fromJson($stdout);
        } catch (UnexpectedValueException $exception) {
            $detail = trim($stderr) === '' ? '' : "\n" . trim($stderr);
            throw new ProjectException('The project could not be inspected: ' . $exception->getMessage() . $detail);
        }
        if ($code !== 0) {
            throw new ProjectException("The project could not be inspected (exit code {$code}).");
        }

        if (!$routes || $inspection->apps === []) {
            return $inspection;
        }

        $appRoutes = [];
        $problems = [];
        foreach ($inspection->apps as $app) {
            if (!is_string($app) || $app === '') {
                $problems[] = ['app' => (string) json_encode($app, JSON_UNESCAPED_SLASHES), 'problem' => 'is not a Class::method name'];
                continue;
            }
            if (isset($appRoutes[$app]) || in_array($app, array_column($problems, 'app'), true)) {
                continue;
            }
            $result = $this->inspectApp($root, $app);
            is_string($result) ? $problems[] = ['app' => $app, 'problem' => $result] : $appRoutes[$app] = $result;
        }

        return $inspection->withApps($appRoutes, $problems);
    }

    /**
     * Boots one declared app in its own process.
     *
     * @return list<array{methods: list<string>, pattern: string, handler: string, name: ?string}>|string its routes, or
     *     why they could not be read
     */
    private function inspectApp(string $root, string $app): array|string
    {
        [$code, $stdout, $stderr, $stopped] = $this->run([PHP_BINARY, self::SCRIPT, $root, '--app=' . $app], $root);
        if ($stopped) {
            return "did not finish booting and was stopped after {$this->timeout} seconds";
        }
        $data = json_decode($stdout);
        if ($data instanceof \stdClass && isset($data->error) && is_string($data->error)) {
            // The inspection script's own report, such as "names a method that is not public and static".
            return $data->error;
        }
        if ($code === 0 && $data instanceof \stdClass && isset($data->routes) && is_array($data->routes)) {
            return Inspection::fromJson($stdout)->routes ?? [];
        }
        // A fatal error, or an exit from inside the boot: the process ended without its result.
        $printed = (string) preg_replace('/\s+/', ' ', trim($stdout . "\n" . $stderr));
        $detail = $printed === '' ? '' : ': ' . (strlen($printed) > 400 ? substr($printed, 0, 400) . '...' : $printed);

        return "ended with exit code {$code} before reporting its routes{$detail}";
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string, 3: bool} exit code, standard output, standard error, and whether the
     *     process was stopped at the time limit
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
        $deadline = hrtime(true) + $this->timeout * 1_000_000_000;
        $stopped = false;
        do {
            if (hrtime(true) >= $deadline) {
                $stopped = true;
                break;
            }
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

        if ($stopped) {
            // Terminated, then killed if it does not end, and reaped by proc_close(), so that no inspection process
            // is left running.
            proc_terminate($process, 15);
            for ($i = 0; $i < 50 && proc_get_status($process)['running']; $i++) {
                usleep(100_000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);

            return [-1, $stdout, $stderr, true];
        }

        return [proc_close($process), $stdout, $stderr, false];
    }
}
