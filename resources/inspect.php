<?php

/**
 * Run by `kipchak routes` and `kipchak openapi:validate` in a separate PHP process, with the project's own autoloader,
 * so that the project's versions of swagger-php, Slim and its drivers are used (SR-2026-012 §3.3.5):
 *
 *     php inspect.php <project root> [--no-document] [--no-routes]
 *
 * Writes one JSON object to standard output: the OpenAPI document and the warnings swagger-php logged while
 * generating it, which pipeline generated it ("project" for Api\Utils\OpenApi::generate(), "fallback" for this
 * script's own) and whether the installed kipchak/core has the Envelope processor, and the routes the application
 * registers. Anything the project prints while booting goes to
 * standard error instead. Exits with 2 when either part cannot be produced, with the reason in "error".
 *
 * This file uses nothing from kipchak/cli, whose classes are not loaded here.
 */

declare(strict_types=1);

$root = $argv[1] ?? '';
$withDocument = !in_array('--no-document', $argv, true);
$withRoutes = !in_array('--no-routes', $argv, true);

$finish = static function (array $result, int $code): never {
    $printed = '';
    while (ob_get_level() > 0) {
        $printed = ob_get_clean() . $printed;
    }
    if ($printed !== '') {
        fwrite(STDERR, $printed);
    }
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION));
    exit($code);
};

if (!is_file($root . '/vendor/autoload.php')) {
    $finish(['error' => 'vendor/autoload.php was not found. Run composer install first.'], 2);
}

chdir($root);
ob_start();
require $root . '/vendor/autoload.php';

$result = [];
try {
    if ($withDocument) {
        $logger = new class extends Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                // swagger-php logs each file it reads at debug level; only warnings and above are problems.
                if (!in_array($level, [Psr\Log\LogLevel::DEBUG, Psr\Log\LogLevel::INFO, Psr\Log\LogLevel::NOTICE], true)) {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $envelope = class_exists(Kipchak\Core\OpenApi\Envelope::class);
        if (class_exists(Api\Utils\OpenApi::class) && method_exists(Api\Utils\OpenApi::class, 'generate')) {
            $pipeline = 'project';
            $document = Api\Utils\OpenApi::generate($logger);
        } else {
            // A project without the starter's generator: document responses as Kipchak sends them, when core can.
            $pipeline = 'fallback';
            $generator = new OpenApi\Generator($logger);
            if ($envelope) {
                $generator = $generator->withProcessorPipeline(
                    static fn ($p) => $p->add(new Kipchak\Core\OpenApi\Envelope(errors: true)),
                );
            }
            $document = $generator->generate([$root . '/api']);
        }
        if ($document === null) {
            $finish(['error' => 'No OpenAPI attributes were found in api/.'], 2);
        }
        // Decoded as objects, so that an empty object stays {} when encoded again.
        $result['document'] = json_decode($document->toJson(), flags: JSON_THROW_ON_ERROR);
        $result['warnings'] = $logger->warnings;
        $result['pipeline'] = $pipeline;
        $result['envelopeAvailable'] = $envelope;
    }

    if ($withRoutes) {
        $app = Kipchak\Core\Api::boot();
        $result['routes'] = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $callable = $route->getCallable();
            $handler = match (true) {
                is_array($callable) && count($callable) === 2 => (is_object($callable[0]) ? $callable[0]::class : (string) $callable[0]) . '::' . $callable[1],
                is_string($callable) => $callable,
                $callable instanceof Closure => 'Closure',
                is_object($callable) => $callable::class,
                default => '',
            };
            $result['routes'][] = [
                'methods' => array_values($route->getMethods()),
                'pattern' => $route->getPattern(),
                'handler' => $handler,
                'name' => $route->getName(),
            ];
        }

        // Protocol endpoints: route handlers that an installed Kipchak package declares, under
        // extra.kipchak.protocol-endpoints, as serving a protocol rather than REST operations (for example
        // kipchak/driver-mcp's endpoint). Each declaration is checked here, with the project's autoloader: the class
        // must be in the declaring package's own PSR-4 namespace and be a concrete class. A declaration that is not is
        // reported with its problem, and openapi:validate treats it as an error, never as a reason to skip a route.
        $result['protocolEndpoints'] = [];
        $installed = is_file('vendor/composer/installed.json')
            ? json_decode((string) file_get_contents('vendor/composer/installed.json'), true)
            : null;
        foreach (is_array($installed) ? ($installed['packages'] ?? $installed) : [] as $package) {
            $name = (string) ($package['name'] ?? '');
            $declared = $package['extra']['kipchak']['protocol-endpoints'] ?? null;
            if (!str_starts_with($name, 'kipchak/') || $declared === null) {
                continue;
            }
            $namespaces = array_keys((array) ($package['autoload']['psr-4'] ?? []));
            foreach (is_array($declared) ? $declared : [$declared] as $class) {
                $problem = null;
                if (!is_string($class) || $class === '') {
                    $problem = 'is not a class name';
                } elseif (str_contains($class, '*')) {
                    $problem = 'is a wildcard; name the exact handler class';
                } else {
                    $class = ltrim($class, '\\');
                    $inNamespace = array_filter($namespaces, static fn (string $prefix): bool => $prefix !== '' && str_starts_with($class, $prefix));
                    if ($inNamespace === []) {
                        $problem = 'is outside the package\'s own namespace (' . ($namespaces === [] ? 'it declares none' : implode(', ', $namespaces)) . ')';
                    } elseif (interface_exists($class) || trait_exists($class)) {
                        $problem = 'is an interface or a trait; name the concrete handler class';
                    } elseif (!class_exists($class)) {
                        $problem = 'cannot be loaded';
                    } elseif ((new ReflectionClass($class))->isAbstract() || (new ReflectionClass($class))->isEnum()) {
                        $problem = 'is not a concrete class; name the handler class itself';
                    }
                }
                $result['protocolEndpoints'][] = ['package' => $name, 'class' => is_string($class) ? $class : json_encode($class), 'problem' => $problem];
            }
        }
    }
} catch (Throwable $exception) {
    $finish(['error' => $exception::class . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine()], 2);
}

$finish($result, 0);
