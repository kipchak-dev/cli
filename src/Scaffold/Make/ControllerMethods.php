<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use InvalidArgumentException;
use Kipchak\Cli\Scaffold\SourceFile;
use PhpParser\Node\Attribute;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use UnexpectedValueException;

/**
 * Adds an operation to an existing controller for make:endpoint: the method and its OpenAPI attribute go in above the
 * class's closing brace, and the imports they need are added in alphabetical place.
 */
final class ControllerMethods
{
    private const string OPENAPI = 'OpenApi\\Attributes';

    public function add(SourceFile $file, string $class, Endpoint $endpoint, ResponseStyle $style): SourceFile
    {
        $node = $this->classNode($file, $class);
        foreach ($node->getMethods() as $method) {
            if ($method->name->toLowerString() === strtolower($endpoint->action)) {
                throw new InvalidArgumentException("{$class} already has a method {$endpoint->action}().");
            }
        }

        $closingLine = $node->getEndLine();
        if (trim($file->line($closingLine)) !== '}') {
            throw new UnexpectedValueException(
                "The closing brace of {$class} is not on a line of its own, so kipchak cannot add a method to it. Add it by hand:\n"
                . $this->method($endpoint, $this->tag($node, $endpoint), ['OA' => 'OA', 'Request' => 'ServerRequestInterface',
                    'Response' => 'ResponseInterface', 'NotImplemented' => 'HttpNotImplementedException'], $style),
            );
        }

        // Each name is resolved, and its import added, before the method goes in below the imports.
        $names = [];
        foreach ([
            'OA' => [self::OPENAPI, self::OPENAPI, 'OA'],
            'Request' => ['Psr\\Http\\Message\\ServerRequestInterface', null, null],
            'Response' => ['Psr\\Http\\Message\\ResponseInterface', null, null],
            'NotImplemented' => ['Slim\\Exception\\HttpNotImplementedException', null, null],
        ] as $key => [$name, $import, $alias]) {
            [$file, $names[$key]] = $file->nameFor($name, $import, $alias);
        }

        $node = $this->classNode($file, $class);
        $lines = $node->stmts === [] ? [] : [''];
        array_push($lines, ...explode("\n", rtrim($this->method($endpoint, $this->tag($node, $endpoint), $names, $style), "\n")));

        return $file->insertAfter($node->getEndLine() - 1, ...$lines);
    }

    /**
     * @param array{OA: string, Request: string, Response: string, NotImplemented: string} $names
     */
    private function method(Endpoint $endpoint, string $tag, array $names, ResponseStyle $style): string
    {
        $parameters = '';
        if ($endpoint->parameters() !== []) {
            $parameters = "        parameters: [\n";
            foreach ($endpoint->parameters() as $parameter) {
                $parameters .= "            new {$names['OA']}\\Parameter(name: '{$parameter['name']}', in: 'path', required: true, "
                    . "schema: new {$names['OA']}\\Schema(type: '{$parameter['type']}')),\n";
            }
            $parameters .= "        ],\n";
        }

        $schema = $endpoint->dto === null ? "type: 'object'" : "ref: '#/components/schemas/{$endpoint->dto}'";
        $requestBody = $endpoint->hasBody()
            ? "        requestBody: new {$names['OA']}\\RequestBody(required: true, content: new {$names['OA']}\\JsonContent({$schema})),\n"
            : '';

        $status = match ($endpoint->method) {
            'POST' => 201,
            'DELETE' => 204,
            default => 200,
        };
        $responses = $status === 204
            ? "            new {$names['OA']}\\Response(response: 204, description: 'Done.'),\n"
            : implode("\n", [
                "            new {$names['OA']}\\Response(",
                "                response: {$status},",
                "                description: '" . ($status === 201 ? 'The result, as created.' : 'The result.') . "',",
                $style->content($names['OA'], $status, $schema),
                '            ),',
                '',
            ]);
        if ($endpoint->hasBody()) {
            $responses .= "            new {$names['OA']}\\Response(response: 400, description: 'The body is not valid.'),\n";
        }
        if ($endpoint->parameters() !== []) {
            $responses .= $style->notFound($names['OA'], 'Not found.') . "\n";
        }

        $hints = '';
        if ($endpoint->parameters() !== []) {
            $hints .= "        // \$args holds the path parameters, as strings.\n";
        }
        if ($endpoint->hasBody()) {
            $hints .= "        // Read the body once, with (string) \$request->getBody().\n";
        }

        return Templates::render('endpoint-method.php', $names + [
            'attribute' => ucfirst(strtolower($endpoint->method)),
            'openApiPath' => $endpoint->openApiPath(),
            'operationId' => $endpoint->action . $endpoint->controller,
            'summary' => ucfirst(Field::words($endpoint->action)),
            'tag' => addcslashes($tag, "'\\"),
            'parameters' => $parameters,
            'requestBody' => $requestBody,
            'responses' => $responses,
            'action' => $endpoint->action,
            'hints' => $hints,
            'status' => (string) ($status === 204 ? 200 : $status),
            'method' => $endpoint->method,
        ]);
    }

    private function classNode(SourceFile $file, string $class): Stmt\Class_
    {
        foreach ($file->statements as $node) {
            foreach ($node instanceof Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                if ($statement instanceof Stmt\Class_ && strcasecmp((string) $statement->namespacedName?->toString(), $class) === 0) {
                    return $statement;
                }
            }
        }

        throw new UnexpectedValueException("The controller file does not declare the class {$class}.");
    }

    /**
     * The name of the controller's #[OA\Tag], so the operation joins the controller's other operations; the
     * controller's name when it has none.
     */
    private function tag(Stmt\Class_ $node, Endpoint $endpoint): string
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() === self::OPENAPI . '\\Tag') {
                    $name = $this->argument($attribute, 'name', 0);
                    if ($name !== null) {
                        return $name;
                    }
                }
            }
        }

        return $endpoint->controller;
    }

    private function argument(Attribute $attribute, string $name, int $position): ?string
    {
        foreach ($attribute->args as $index => $argument) {
            if (($argument->name?->toString() === $name || ($argument->name === null && $index === $position))
                && $argument->value instanceof String_) {
                return $argument->value->value;
            }
        }

        return null;
    }
}
