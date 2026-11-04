<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use Kipchak\Cli\Scaffold\SourceFile;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Adds a route for make:endpoint inside a route file's group for its API version, `$app->group('/v1', ...)`. A file
 * with no such group, or more than one, is not edited (SR-2026-012 §3.3.4).
 */
final class RouteGroups
{
    /**
     * Whether a route file refers to the controller, to choose the file its routes are in.
     */
    public function refersTo(SourceFile $file, string $controllerClass): bool
    {
        $fetches = new NodeFinder()->find($file->statements, static fn (Node $node): bool => $node instanceof Expr\ClassConstFetch
            && $node->class instanceof Node\Name && strcasecmp($node->class->toString(), $controllerClass) === 0);

        return $fetches !== [];
    }

    /**
     * @return array{0: ?SourceFile, 1: ?string} the file with the route added, or null and the reason it was not
     */
    public function add(SourceFile $file, Endpoint $endpoint, string $controllerClass, string $controllersNamespace): array
    {
        $groups = [];
        foreach ($file->statements as $statement) {
            if ($statement instanceof Stmt\Expression) {
                $group = $this->group($statement->expr, $endpoint->version);
                if ($group !== null) {
                    $groups[] = $group;
                }
            }
        }
        if (count($groups) !== 1) {
            return [null, (count($groups) === 0 ? 'it has no' : 'it has more than one') . " \$app->group('/{$endpoint->version}', function (...) { ... }) to add it to"];
        }

        $closure = $groups[0];
        $variable = $closure->params[0]->var ?? null;
        if (!$variable instanceof Expr\Variable || !is_string($variable->name)) {
            return [null, "its /{$endpoint->version} group's function takes no route collector"];
        }
        if ($this->routes($closure, $variable->name, $endpoint)) {
            return [null, "it already routes {$endpoint->method} {$endpoint->path}"];
        }
        if (!str_starts_with(ltrim($file->line($closure->getEndLine())), '}')) {
            return [null, "the end of its /{$endpoint->version} group is not on a line of its own"];
        }

        [$file, $controller] = $file->nameFor($controllerClass, $controllersNamespace);
        $closure = $this->group($this->groupStatement($file, $endpoint)->expr, $endpoint->version);

        return [$file->insertAfter($closure->getEndLine() - 1, $this->indentation($file, $closure) . $this->route($variable->name, $endpoint, $controller)), null];
    }

    /**
     * The route statement, without indentation.
     */
    public function route(string $variable, Endpoint $endpoint, string $controller): string
    {
        $path = addcslashes($endpoint->routePath, "'\\");

        return "\${$variable}->" . strtolower($endpoint->method) . "('{$path}', [{$controller}::class, '{$endpoint->action}']);";
    }

    /**
     * The function given to `$app->group('/v1', ...)`, also when the group has middleware added to it.
     */
    private function group(Expr $expr, string $version): ?Expr\Closure
    {
        while ($expr instanceof Expr\MethodCall && !($expr->name instanceof Node\Identifier && $expr->name->toLowerString() === 'group')) {
            $expr = $expr->var;
        }
        if (!$expr instanceof Expr\MethodCall || count($expr->args) < 2 || !$expr->args[0] instanceof Node\Arg
            || !$expr->args[0]->value instanceof String_ || trim($expr->args[0]->value->value, '/') !== $version) {
            return null;
        }
        $callable = $expr->args[1] instanceof Node\Arg ? $expr->args[1]->value : null;

        return $callable instanceof Expr\Closure ? $callable : null;
    }

    private function groupStatement(SourceFile $file, Endpoint $endpoint): Stmt\Expression
    {
        foreach ($file->statements as $statement) {
            if ($statement instanceof Stmt\Expression && $this->group($statement->expr, $endpoint->version) !== null) {
                return $statement;
            }
        }

        throw new \LogicException('The group found before the import was added has gone.');
    }

    /**
     * Whether the group already has a route with this method and path.
     */
    private function routes(Expr\Closure $closure, string $variable, Endpoint $endpoint): bool
    {
        $calls = new NodeFinder()->find($closure->stmts, static fn (Node $node): bool => $node instanceof Expr\MethodCall
            && $node->var instanceof Expr\Variable && $node->var->name === $variable
            && $node->name instanceof Node\Identifier && $node->name->toLowerString() === strtolower($endpoint->method)
            && ($node->args[0] ?? null) instanceof Node\Arg && $node->args[0]->value instanceof String_
            && $node->args[0]->value->value === $endpoint->routePath);

        return $calls !== [];
    }

    /**
     * The indentation of the group's last route, or four spaces more than the group's closing line.
     */
    private function indentation(SourceFile $file, Expr\Closure $closure): string
    {
        $last = $closure->stmts[count($closure->stmts) - 1] ?? null;
        $line = $last !== null ? $file->line($last->getStartLine()) : '    ' . $file->line($closure->getEndLine());
        preg_match('/^[ \t]*/', $line, $matches);

        return $matches[0];
    }
}
