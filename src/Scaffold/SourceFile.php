<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * A PHP file the tool edits (SR-2026-012 §3.3.4). The parser finds lines and names; every edit inserts text, so the
 * rest of the file keeps its formatting exactly.
 */
final readonly class SourceFile
{
    /**
     * @var list<Node> the top-level statements, with names resolved
     */
    public array $statements;

    public string $newline;

    public function __construct(public string $content)
    {
        $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($content) ?? [];
        $this->statements = new NodeTraverser(new NameResolver())->traverse($statements);
        $this->newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
    }

    /**
     * @return list<string> lines with their line endings
     */
    public function lines(): array
    {
        return preg_split('/(?<=\n)/', $this->content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public function line(int $number): string
    {
        return $this->lines()[$number - 1] ?? '';
    }

    /**
     * Inserts lines, each given without its line ending, below line $afterLine (0 inserts at the top).
     */
    public function insertAfter(int $afterLine, string ...$lines): self
    {
        $existing = $this->lines();
        array_splice($existing, $afterLine, 0, array_map(fn (string $line): string => $line . $this->newline, $lines));

        return new self(implode('', $existing));
    }

    /**
     * @return array<string, string> lower-case alias => imported class or namespace
     */
    public function imports(): array
    {
        $imports = [];
        foreach ($this->uses() as $node) {
            foreach ($node->uses as $use) {
                $imports[strtolower($use->getAlias()->toString())] = $use->name->toString();
            }
        }

        return $imports;
    }

    /**
     * How this file can refer to a class through its imports: 'Orders' when the class is imported, 'Controllers\v1\Orders'
     * when its namespace Api\Controllers is. Null when no import covers it.
     */
    public function reference(string $class): ?string
    {
        foreach ($this->uses() as $node) {
            foreach ($node->uses as $use) {
                $imported = $use->name->toString();
                $alias = $use->getAlias()->toString();
                if (strcasecmp($imported, $class) === 0) {
                    return $alias;
                }
                if (stripos($class, $imported . '\\') === 0) {
                    return $alias . substr($class, strlen($imported));
                }
            }
        }

        return null;
    }

    /**
     * The name this file uses for $class, importing $import (the class or one of its namespaces) when no import covers
     * it yet. When the import's alias already names something else, or the file imports nothing, the class is used
     * fully qualified instead.
     *
     * @return array{0: self, 1: string} the file, with any import added, and the name to use
     */
    public function nameFor(string $class, ?string $import = null, ?string $alias = null): array
    {
        $reference = $this->reference($class);
        if ($reference !== null) {
            return [$this, $reference];
        }

        $import ??= $class;
        $shortName = str_contains($import, '\\') ? substr($import, (int) strrpos($import, '\\') + 1) : $import;
        $alias ??= $shortName;
        $uses = $this->uses();
        if ($uses === [] || array_key_exists(strtolower($alias), $this->imports())) {
            return [$this, '\\' . $class];
        }

        $statement = $alias === $shortName ? "use {$import};" : "use {$import} as {$alias};";
        $file = $this->insertAfter($this->importLine($uses, $import), $statement);

        return [$file, (string) $file->reference($class)];
    }

    /**
     * The line to insert a new import below: in alphabetical place when the imports are in alphabetical order, as the
     * starter's are; otherwise below the last.
     *
     * @param non-empty-list<Stmt\Use_> $uses
     */
    private function importLine(array $uses, string $import): int
    {
        $names = array_map(static fn (Stmt\Use_ $use): string => $use->uses[0]->name->toString(), $uses);
        $sorted = $names;
        usort($sorted, 'strcasecmp');
        if ($names !== $sorted) {
            return $uses[count($uses) - 1]->getEndLine();
        }

        $line = $uses[0]->getStartLine() - 1;
        foreach ($uses as $use) {
            if (strcasecmp($use->uses[0]->name->toString(), $import) < 0) {
                $line = $use->getEndLine();
            }
        }

        return $line;
    }

    /**
     * @return list<Stmt\Use_> the top-level class imports, including those inside a namespace block
     */
    private function uses(): array
    {
        $uses = [];
        foreach ($this->statements as $node) {
            foreach ($node instanceof Stmt\Namespace_ ? $node->stmts : [$node] as $statement) {
                if ($statement instanceof Stmt\Use_ && $statement->type === Stmt\Use_::TYPE_NORMAL) {
                    $uses[] = $statement;
                }
            }
        }

        return $uses;
    }
}
