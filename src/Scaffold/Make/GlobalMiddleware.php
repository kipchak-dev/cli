<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use Kipchak\Cli\Scaffold\SourceFile;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Registers a project middleware for every request, for make:middleware --global: `$app->add(new Name());` goes in
 * middlewares/middlewares.php above the outer `Error::initialise($app);`, as `kipchak add` places a package.
 */
final class GlobalMiddleware
{
    private const string ERROR_CLASS = 'Kipchak\\Middleware\\Error\\Error';

    /**
     * @return array{0: ?SourceFile, 1: list<string>} the file with the middleware added, or null, and messages
     */
    public function add(SourceFile $file, string $class): array
    {
        $added = new NodeFinder()->find($file->statements, static fn (Node $node): bool => $node instanceof Expr\New_
            && $node->class instanceof Node\Name && strcasecmp($node->class->toString(), $class) === 0);
        if ($added !== []) {
            return [null, ["middlewares/middlewares.php already adds {$class}."]];
        }

        [$file, $name] = $file->nameFor($class);
        $statement = "\$app->add(new {$name}());";

        $errors = [];
        foreach ($file->statements as $node) {
            if ($node instanceof Stmt\Expression && $node->expr instanceof Expr\StaticCall && $node->expr->class instanceof Node\Name
                && $node->expr->class->toString() === self::ERROR_CLASS) {
                $errors[] = $node;
            }
        }

        if (count($errors) < 2) {
            $messages = count($errors) === 1
                ? ['middlewares/middlewares.php initialises the Error middleware once. Add a second `Error::initialise($app);` '
                    . 'as its last line, so that exceptions from other middleware are answered with JSON: https://kipchak.dev/docs/upgrades']
                : [];

            return [new SourceFile(rtrim($file->content, "\r\n") . $file->newline . $statement . $file->newline), $messages];
        }

        // Above the outer Error, joining the block of middleware rather than the blank line that separates it.
        $floor = $errors[0]->getEndLine();
        $line = $errors[count($errors) - 1]->getStartLine() - 1;
        while ($line > $floor && trim($file->line($line)) === '') {
            $line--;
        }

        return [$file->insertAfter($line, $statement), []];
    }
}
