<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Validate;

use JsonException;
use UnexpectedValueException;

/**
 * What the inspection script read from a project (SR-2026-012 §3.3.5): the OpenAPI document it generates, the
 * warnings swagger-php logged meanwhile, and the routes the application registers. The MCP server (KIP-X2) can build
 * one from what an assistant supplies.
 */
final readonly class Inspection
{
    /**
     * @param ?object $document the document as decoded JSON objects, so that {} and [] stay distinct
     * @param list<string> $warnings
     * @param ?list<array{methods: list<string>, pattern: string, handler: string, name: ?string}> $routes null when
     *     routes were not read
     */
    public function __construct(
        public ?object $document,
        public array $warnings = [],
        public ?array $routes = null,
    ) {}

    /**
     * From the inspection script's output.
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('The inspection script did not print JSON.');
        }
        if (!$data instanceof \stdClass) {
            throw new UnexpectedValueException('The inspection script did not print a JSON object.');
        }
        if (isset($data->error)) {
            throw new UnexpectedValueException((string) $data->error);
        }

        $routes = null;
        if (isset($data->routes)) {
            $routes = [];
            foreach ($data->routes as $route) {
                $routes[] = [
                    'methods' => array_values(array_map('strval', (array) $route->methods)),
                    'pattern' => (string) $route->pattern,
                    'handler' => (string) ($route->handler ?? ''),
                    'name' => isset($route->name) ? (string) $route->name : null,
                ];
            }
        }

        return new self(
            isset($data->document) && is_object($data->document) ? $data->document : null,
            array_values(array_map('strval', (array) ($data->warnings ?? []))),
            $routes,
        );
    }

    /**
     * The document as associative arrays, for reading.
     *
     * @return array<string, mixed>
     */
    public function documentArray(): array
    {
        return $this->document === null ? [] : (array) json_decode((string) json_encode($this->document), true);
    }
}
