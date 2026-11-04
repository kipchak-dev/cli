<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Add;

use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Inserts a package's initialise() statement into drivers/drivers.php or middlewares/middlewares.php, where its
 * `after` and `before` lists allow (SR-2026-012 §3.3.4, §3.3.6).
 *
 * The parser is used only to find the existing initialise() calls and their lines; the statement (and its `use`
 * import) is inserted as text, so the rest of the file keeps its formatting exactly.
 */
final class Registrar
{
    private const string ERROR_CLASS = 'Kipchak\\Middleware\\Error\\Error';

    /**
     * @param array<string, PackageMetadata> $known metadata of every installed Kipchak package, by name
     */
    public function register(string $content, PackageMetadata $package, array $known): Registration
    {
        $statements = $this->initialiseCalls($content);
        $classToPackage = [];
        foreach ($known + [$package->name => $package] as $metadata) {
            $classToPackage[$metadata->className()] = $metadata->name;
        }

        foreach ($statements as $statement) {
            if ($statement['class'] === $package->className()) {
                return Registration::unchanged("{$package->name} is already initialised in {$package->registrationFile()}.");
            }
        }

        $messages = [];
        [$regionStart, $regionEnd] = [0, PHP_INT_MAX];
        $fallback = null;
        if ($package->isMiddleware()) {
            $errors = array_values(array_filter($statements, static fn (array $s): bool => $s['class'] === self::ERROR_CLASS));
            if (count($errors) >= 2) {
                $regionStart = $errors[0]['end'];
                $regionEnd = $errors[count($errors) - 1]['start'];
                $fallback = ['before', $regionEnd];
            } elseif (count($errors) === 1) {
                $regionStart = $errors[0]['end'];
                $messages[] = 'middlewares/middlewares.php initialises the Error middleware once. Add a second '
                    . '`Error::initialise($app);` as its last line, so that exceptions from other middleware are '
                    . 'answered with JSON: https://kipchak.dev/docs/upgrades';
            }
        }

        $afterEnd = null;
        $beforeStart = null;
        foreach ($statements as $statement) {
            $name = $classToPackage[$statement['class']] ?? null;
            if ($name !== null && in_array($name, $package->after, true)) {
                $afterEnd = max($afterEnd ?? 0, $statement['end']);
            }
            if ($name !== null && in_array($name, $package->before, true)) {
                $beforeStart = min($beforeStart ?? PHP_INT_MAX, $statement['start']);
            }
        }

        // Insert after the last package it must follow; otherwise before the first it must precede; otherwise at the
        // end of the region: before the outer Error for middleware, at the end of the file for drivers.
        $insertAfterLine = match (true) {
            $afterEnd !== null => max($afterEnd, $regionStart),
            $beforeStart !== null => $beforeStart - 1,
            $fallback !== null => $this->lastNonBlankLineBefore($content, $fallback[1], $regionStart),
            default => null,
        };

        if (($beforeStart !== null && $insertAfterLine !== null && $insertAfterLine >= $beforeStart)
            || ($insertAfterLine !== null && ($insertAfterLine < $regionStart || $insertAfterLine >= $regionEnd))) {
            return Registration::unchanged(
                "{$package->name} cannot be placed in {$package->registrationFile()}: the packages it must follow "
                . 'and those it must precede are initialised in the wrong order. Add this line by hand: '
                . $package->initialise,
            );
        }

        // The statement goes in first, below the imports, so the import's line number is unaffected by it.
        $call = $this->call($content, $package);
        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        if ($insertAfterLine === null) {
            $content = rtrim($content, "\r\n") . $newline . $call . $newline;
        } else {
            $lines = $this->lines($content);
            array_splice($lines, $insertAfterLine, 0, [$call . $newline]);
            $content = implode('', $lines);
        }

        return Registration::changed($this->import($content, $package), $messages);
    }

    /**
     * The statement to insert: by short name when the class is, or can be, imported; otherwise fully qualified.
     */
    private function call(string $content, PackageMetadata $package): string
    {
        $imports = $this->imports($content);
        $imported = $imports[strtolower($this->shortName($package))] ?? null;
        $byShortName = $imports !== [] && ($imported === null || $imported === $package->className());
        $name = $byShortName ? $this->shortName($package) : '\\' . $package->className();

        return "{$name}::initialise({$package->argument()});";
    }

    /**
     * Adds `use Vendor\Class;` after the last top-level use statement, unless it is already imported or the short
     * name belongs to another class (the call is then fully qualified).
     */
    private function import(string $content, PackageMetadata $package): string
    {
        $imports = $this->imports($content);
        if (array_key_exists(strtolower($this->shortName($package)), $imports)) {
            return $content;
        }

        $lastUseLine = null;
        foreach ($this->parse($content) as $node) {
            if ($node instanceof Stmt\Use_) {
                $lastUseLine = $node->getEndLine();
            }
        }
        if ($lastUseLine === null) {
            // A file with no imports gets the call fully qualified (see call()), and no import.
            return $content;
        }

        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = $this->lines($content);
        array_splice($lines, $lastUseLine, 0, ["use {$package->className()};{$newline}"]);

        return implode('', $lines);
    }

    /**
     * @return array<string, string> lower-case alias => imported class
     */
    private function imports(string $content): array
    {
        $imports = [];
        foreach ($this->parse($content) as $node) {
            if ($node instanceof Stmt\Use_) {
                foreach ($node->uses as $use) {
                    $imports[strtolower($use->getAlias()->toString())] = $use->name->toString();
                }
            }
        }

        return $imports;
    }

    /**
     * The last line above $line that is not blank, so that a statement added above the outer Error joins the block
     * of middleware rather than the blank line that separates it.
     */
    private function lastNonBlankLineBefore(string $content, int $line, int $floor): int
    {
        $lines = $this->lines($content);
        $candidate = $line - 1;
        while ($candidate > $floor && trim($lines[$candidate - 1] ?? '') === '') {
            $candidate--;
        }

        return $candidate;
    }

    private function shortName(PackageMetadata $package): string
    {
        $class = $package->className();

        return substr($class, (int) strrpos($class, '\\') + 1);
    }

    /**
     * @return list<array{class: string, start: int, end: int}>
     */
    private function initialiseCalls(string $content): array
    {
        $calls = [];
        foreach ($this->parse($content) as $node) {
            if ($node instanceof Stmt\Expression && $node->expr instanceof StaticCall
                && $node->expr->class instanceof Name && $node->expr->name instanceof \PhpParser\Node\Identifier
                && $node->expr->name->toLowerString() === 'initialise') {
                $calls[] = [
                    'class' => $node->expr->class->toString(),
                    'start' => $node->getStartLine(),
                    'end' => $node->getEndLine(),
                ];
            }
        }

        return $calls;
    }

    /**
     * @return list<\PhpParser\Node>
     */
    private function parse(string $content): array
    {
        $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($content) ?? [];
        $traverser = new NodeTraverser(new NameResolver());

        return $traverser->traverse($statements);
    }

    /**
     * @return list<string> lines with their line endings
     */
    private function lines(string $content): array
    {
        return preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
