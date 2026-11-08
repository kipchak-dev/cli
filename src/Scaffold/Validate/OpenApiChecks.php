<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Validate;

/**
 * The checks of openapi:validate (SR-2026-012 §3.3.5), each with a stable identifier. Reads nothing from disk except
 * the bundled OpenAPI schemas.
 */
final class OpenApiChecks
{
    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * Routes that serve the document itself, which the document does not describe.
     */
    private const string DOCUMENT_ROUTE = '#/openapi\.(json|ya?ml)$#';

    /**
     * A route parameter, {name} or {name:pattern}, where the pattern may hold one level of braces ({id:[0-9]{4}}).
     */
    private const string PARAMETER = '/\{(\w+)(?::((?:[^{}]|\{[^{}]*\})+))?\}/';

    /**
     * The response envelope Http\Response::json() writes (meta only for a paged list or other endpoint metadata), which
     * a response may define inline around its data.
     */
    private const array ENVELOPE = ['code', 'status', 'meta', 'data'];

    /**
     * The extension that marks a response, or an operation, as not sent in the envelope.
     */
    private const string OPT_OUT = 'x-kipchak-envelope';

    public function __construct(private readonly SchemaValidator $schemaValidator = new SchemaValidator()) {}

    /**
     * @param list<string> $ignoredRoutes routes the document deliberately leaves out, as 'GET /internal/health'
     * @return list<Finding>
     */
    public function check(Inspection $inspection, array $ignoredRoutes = []): array
    {
        if ($inspection->document === null) {
            return [];
        }
        $document = $inspection->documentArray();

        $findings = $this->schemaValidator->validate($inspection->document);
        foreach ($inspection->warnings as $warning) {
            $findings[] = new Finding('OAS-002', Severity::Error, 'swagger-php', $warning);
        }
        array_push(
            $findings,
            ...$this->references($document),
            ...($inspection->routes === null ? [] : $this->routes($document, $inspection->routes, $ignoredRoutes)),
            ...$this->operationFields($document),
            ...$this->inlineBodies($document),
            ...$this->schemaProperties($document),
            ...($inspection->hasEnvelopeComponent('Success') ? [] : $this->unenvelopedSuccesses($document)),
        );

        return $findings;
    }

    /**
     * The path as the OpenAPI document writes it: {id} where the route has {id:[0-9]+}, and one path for each
     * optional segment of the route ('/sultans[/{id}]' gives '/sultans' and '/sultans/{id}').
     *
     * @return list<string>
     */
    public static function documentPaths(string $pattern): array
    {
        $pattern = (string) preg_replace_callback(self::PARAMETER, static fn (array $m): string => '{' . $m[1] . '}', $pattern);
        $parts = explode('[', str_replace(']', '', $pattern));
        $paths = [];
        $path = '';
        foreach ($parts as $part) {
            $path .= $part;
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * OAS-003: every $ref resolves inside the document.
     *
     * @param array<string, mixed> $document
     * @return list<Finding>
     */
    private function references(array $document): array
    {
        $findings = [];
        $this->walk($document, '', function (string $ref, string $location) use ($document, &$findings): void {
            if (!str_starts_with($ref, '#/')) {
                $findings[] = new Finding('OAS-003', Severity::Error, $location, "{$ref} is not a reference inside the document.");
            } elseif (!$this->resolves($document, $ref)) {
                $findings[] = new Finding('OAS-003', Severity::Error, $location, "{$ref} does not resolve: the document has nothing there.");
            }
        });

        return $findings;
    }

    /**
     * @param callable(string, string): void $visit called with each $ref and the pointer of the object holding it
     */
    private function walk(mixed $node, string $pointer, callable $visit): void
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $visit($node['$ref'], $pointer);
        }
        foreach ($node as $key => $child) {
            $this->walk($child, $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key), $visit);
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function resolves(array $document, string $ref): bool
    {
        $node = $document;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }

        return true;
    }

    /**
     * KIP-001: every route has an operation and every operation a route. KIP-002: every path parameter of a route is
     * declared on its operation.
     *
     * @param array<string, mixed> $document
     * @param list<array{methods: list<string>, pattern: string, handler: string, name: ?string}> $routes
     * @param list<string> $ignoredRoutes
     * @return list<Finding>
     */
    private function routes(array $document, array $routes, array $ignoredRoutes): array
    {
        $operations = [];
        foreach ($this->operations($document) as [$method, $path, $operation, $pathItem]) {
            $operations[strtoupper($method) . ' ' . $path] = [$operation, $pathItem];
        }
        $ignored = array_map(static fn (string $route): string => (string) preg_replace('/\s+/', ' ', trim($route)), $ignoredRoutes);

        $findings = [];
        $routed = [];
        foreach ($routes as $route) {
            if (preg_match(self::DOCUMENT_ROUTE, $route['pattern']) === 1) {
                continue;
            }
            foreach ($route['methods'] as $method) {
                $method = strtoupper($method);
                if (in_array("{$method} {$route['pattern']}", $ignored, true)) {
                    continue;
                }
                foreach (self::documentPaths($route['pattern']) as $path) {
                    $key = "{$method} {$path}";
                    if (in_array($key, $ignored, true)) {
                        continue;
                    }
                    $routed[$key] = true;
                    if (!isset($operations[$key])) {
                        $handler = $route['handler'] === '' ? '' : " ({$route['handler']})";
                        $findings[] = new Finding('KIP-001', Severity::Error, $key, "The route{$handler} has no operation in the document.");
                        continue;
                    }

                    $declared = $this->pathParameters($document, ...$operations[$key]);
                    preg_match_all('/\{(\w+)\}/', $path, $matches);
                    foreach ($matches[1] as $name) {
                        if (!in_array($name, $declared, true)) {
                            $findings[] = new Finding('KIP-002', Severity::Error, $key, "The path parameter {$name} is not declared as a parameter of the operation.");
                        }
                    }
                }
            }
        }

        foreach (array_keys($operations) as $key) {
            if (!isset($routed[$key]) && !in_array($key, $ignored, true)) {
                $findings[] = new Finding('KIP-001', Severity::Error, $key, 'The operation has no route, so the API answers it with 404.');
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $pathItem
     * @return list<string> the names of the parameters declared in: path, on the operation or its path
     */
    private function pathParameters(array $document, array $operation, array $pathItem): array
    {
        $names = [];
        foreach ([...($pathItem['parameters'] ?? []), ...($operation['parameters'] ?? [])] as $parameter) {
            $parameter = $this->dereference($document, $parameter);
            if (($parameter['in'] ?? null) === 'path' && isset($parameter['name'])) {
                $names[] = (string) $parameter['name'];
            }
        }

        return $names;
    }

    /**
     * KIP-003: every operation has a unique operationId, a summary and a tag.
     *
     * @param array<string, mixed> $document
     * @return list<Finding>
     */
    private function operationFields(array $document): array
    {
        $findings = [];
        $ids = [];
        foreach ($this->operations($document) as [$method, $path, $operation]) {
            $location = strtoupper($method) . ' ' . $path;
            $id = $operation['operationId'] ?? '';
            if (!is_string($id) || $id === '') {
                $findings[] = new Finding('KIP-003', Severity::Error, $location, 'The operation has no operationId.');
            } elseif (isset($ids[$id])) {
                $findings[] = new Finding('KIP-003', Severity::Error, $location, "The operationId {$id} is also used by {$ids[$id]}.");
            } else {
                $ids[$id] = $location;
            }
            if (!is_string($operation['summary'] ?? null) || trim($operation['summary']) === '') {
                $findings[] = new Finding('KIP-003', Severity::Error, $location, 'The operation has no summary.');
            }
            if (!is_array($operation['tags'] ?? null) || $operation['tags'] === []) {
                $findings[] = new Finding('KIP-003', Severity::Error, $location, 'The operation has no tag.');
            }
        }

        return $findings;
    }

    /**
     * KIP-004: request and response bodies refer to component schemas instead of defining objects inline. A response
     * may define the envelope (code, status, data) inline; its data is checked in turn.
     *
     * @param array<string, mixed> $document
     * @return list<Finding>
     */
    private function inlineBodies(array $document): array
    {
        $bodies = [];
        foreach ($this->operations($document) as [$method, $path, $operation]) {
            $location = strtoupper($method) . ' ' . $path;
            if (is_array($operation['requestBody'] ?? null)) {
                $bodies[] = [$operation['requestBody'], "{$location} request body", false];
            }
            foreach ((array) ($operation['responses'] ?? []) as $status => $response) {
                $bodies[] = [$response, "{$location} response {$status}", true];
            }
        }
        foreach ((array) ($document['components']['requestBodies'] ?? []) as $name => $body) {
            $bodies[] = [$body, "request body {$name}", false];
        }
        foreach ((array) ($document['components']['responses'] ?? []) as $name => $response) {
            $bodies[] = [$response, "response {$name}", true];
        }

        $findings = [];
        foreach ($bodies as [$body, $location, $envelope]) {
            // A body given by $ref is a component, checked once under its own name.
            if (!is_array($body) || isset($body['$ref'])) {
                continue;
            }
            foreach ((array) ($body['content'] ?? []) as $mediaType => $content) {
                if (is_array($content['schema'] ?? null) && $this->hasInlineObject($content['schema'], $envelope)) {
                    $findings[] = new Finding('KIP-004', Severity::Warning, "{$location} ({$mediaType})",
                        'The body defines an object inline. Describe it with a DTO and refer to its schema with ref.');
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function hasInlineObject(array $schema, bool $envelope): bool
    {
        if (isset($schema['$ref'])) {
            return false;
        }
        if (is_array($schema['properties'] ?? null) && $schema['properties'] !== []) {
            if (!$envelope || array_diff(array_keys($schema['properties']), self::ENVELOPE) !== []) {
                return true;
            }

            return is_array($schema['properties']['data'] ?? null) && $this->hasInlineObject($schema['properties']['data'], false);
        }
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            foreach ((array) ($schema[$keyword] ?? []) as $part) {
                if (is_array($part) && $this->hasInlineObject($part, $envelope)) {
                    return true;
                }
            }
        }

        return is_array($schema['items'] ?? null) && $this->hasInlineObject($schema['items'], false);
    }

    /**
     * KIP-005: every property of a component schema has a description and an example. A property that refers to
     * another schema takes both from that schema, and an array of such references needs no example.
     *
     * @param array<string, mixed> $document
     * @return list<Finding>
     */
    private function schemaProperties(array $document): array
    {
        $findings = [];
        foreach ((array) ($document['components']['schemas'] ?? []) as $schemaName => $schema) {
            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                if (!is_array($property) || isset($property['$ref']) || isset($property['allOf']) || isset($property['oneOf']) || isset($property['anyOf'])) {
                    continue;
                }
                $location = "schema {$schemaName}, property {$name}";
                if (!is_string($property['description'] ?? null) || trim($property['description']) === '') {
                    $findings[] = new Finding('KIP-005', Severity::Warning, $location, 'The property has no description.');
                }
                $referencesItems = isset($property['items']['$ref']);
                if (!$referencesItems && !array_key_exists('example', $property) && !array_key_exists('examples', $property)) {
                    $findings[] = new Finding('KIP-005', Severity::Warning, $location, 'The property has no example.');
                }
            }
        }

        return $findings;
    }

    /**
     * KIP-006: a JSON success is documented in the envelope Http\Response::json() sends it in, {code, status, data}, not as
     * its data alone. Register kipchak/core's Envelope processor to state it, or define it inline. A route that answers
     * with Http\Response::raw() is marked x-kipchak-envelope: false, on the response or the operation.
     *
     * Not run on a document the Envelope processor generated: it has wrapped every JSON success, and removed the marker
     * from those it left alone on purpose.
     *
     * @param array<string, mixed> $document
     * @return list<Finding>
     */
    private function unenvelopedSuccesses(array $document): array
    {
        $findings = [];
        foreach ($this->operations($document) as [$method, $path, $operation]) {
            if (($operation[self::OPT_OUT] ?? null) === false) {
                continue;
            }
            foreach ((array) ($operation['responses'] ?? []) as $status => $response) {
                $response = $this->dereference($document, $response);
                if (!preg_match('/^2\d\d$/', (string) $status) || (string) $status === '204' || ($response[self::OPT_OUT] ?? null) === false) {
                    continue;
                }
                foreach ((array) ($response['content'] ?? []) as $mediaType => $content) {
                    if (preg_match('#^application/(.+\+)?json$#', (string) $mediaType) === 1 && is_array($content['schema'] ?? null)
                        && !$this->isEnveloped($document, $content['schema'])) {
                        $findings[] = new Finding('KIP-006', Severity::Warning, strtoupper($method) . " {$path} response {$status} ({$mediaType})",
                            'The response is documented as its data alone, but Http\\Response::json() sends it in the envelope {code, status, data}. '
                            . 'Register Kipchak\\Core\\OpenApi\\Envelope in Api\\Utils\\OpenApi, or mark a route that answers with Http\\Response::raw() x-kipchak-envelope: false.');
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Whether the schema states the envelope: code and data among its properties, or in a part of its allOf, here or
     * in the component it refers to.
     *
     * @param array<string, mixed> $schema
     */
    private function isEnveloped(array $document, array $schema, int $depth = 0): bool
    {
        if ($depth > 5) {
            return false;
        }
        if (isset($schema['$ref'])) {
            return $this->isEnveloped($document, $this->dereference($document, $schema), $depth + 1);
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        if (array_key_exists('code', $properties) && array_key_exists('data', $properties)) {
            return true;
        }
        foreach ((array) ($schema['allOf'] ?? []) as $part) {
            if (is_array($part) && $this->isEnveloped($document, $part, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $document
     * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}> method, path,
     *     operation, path item
     */
    private function operations(array $document): array
    {
        $operations = [];
        foreach ((array) ($document['paths'] ?? []) as $path => $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }
            foreach (self::METHODS as $method) {
                if (is_array($pathItem[$method] ?? null)) {
                    $operations[] = [$method, (string) $path, $pathItem[$method], $pathItem];
                }
            }
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function dereference(array $document, mixed $node): array
    {
        $seen = 0;
        while (is_array($node) && is_string($node['$ref'] ?? null) && str_starts_with($node['$ref'], '#/') && $seen++ < 10) {
            $target = $document;
            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $target = is_array($target) ? ($target[str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment))] ?? null) : null;
            }
            $node = $target;
        }

        return is_array($node) ? $node : [];
    }
}
