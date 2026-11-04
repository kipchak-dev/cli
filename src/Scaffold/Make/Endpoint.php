<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use InvalidArgumentException;

/**
 * What make:endpoint adds: `POST /v1/orders/{id:[0-9]+}/cancel Orders::cancel`. The path starts with the API version,
 * which names the controller folder and the route group; the rest is the route inside that group.
 */
final readonly class Endpoint
{
    private const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * A route parameter, {name} or {name:pattern}, where the pattern may hold one level of braces ({id:[0-9]{4}}).
     */
    private const string PARAMETER = '/\{(\w+)(?::((?:[^{}]|\{[^{}]*\})+))?\}/';

    public string $method;
    public string $version;
    public string $routePath;
    public string $controller;
    public string $action;

    public function __construct(string $method, public string $path, string $handler, public ?string $dto = null)
    {
        $this->method = strtoupper($method);
        if (!in_array($this->method, self::METHODS, true)) {
            throw new InvalidArgumentException("'{$method}' is not an HTTP method make:endpoint writes. Use " . implode(', ', self::METHODS) . '.');
        }

        if (preg_match('#^/(v[0-9]+)(/.*)?$#', $path, $matches) !== 1 || str_contains($path, '//')) {
            throw new InvalidArgumentException("'{$path}' does not start with the API version. Give the whole path, such as /v1/orders/{id}.");
        }
        $this->version = $matches[1];
        $this->routePath = rtrim($matches[2] ?? '', '/');

        if (preg_match('/^([A-Za-z0-9]+)::([A-Za-z0-9]+)$/', $handler, $matches) !== 1) {
            throw new InvalidArgumentException("'{$handler}' is not Controller::method, such as Orders::cancel.");
        }
        $this->controller = Names::className($matches[1], 'controller');
        if (preg_match('/^[a-z][A-Za-z0-9]*$/', $matches[2]) !== 1) {
            throw new InvalidArgumentException("'{$matches[2]}' is not a method name. Use camelCase, such as cancel or listItems.");
        }
        $this->action = $matches[2];
        if ($dto !== null) {
            Names::className($dto, 'DTO');
        }
    }

    /**
     * The path as the OpenAPI document writes it: {id} where the route has {id:[0-9]+}.
     */
    public function openApiPath(): string
    {
        return (string) preg_replace_callback(self::PARAMETER, static fn (array $m): string => '{' . $m[1] . '}', $this->path);
    }

    /**
     * @return list<array{name: string, type: string}> each path parameter, with its OpenAPI type: integer when the
     *     route allows only digits, string otherwise
     */
    public function parameters(): array
    {
        preg_match_all(self::PARAMETER, $this->path, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $m): array => [
            'name' => $m[1],
            'type' => in_array($m[2] ?? '', ['[0-9]+', '\d+'], true) ? 'integer' : 'string',
        ], $matches);
    }

    public function hasBody(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH'], true);
    }
}
