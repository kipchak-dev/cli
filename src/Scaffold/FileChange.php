<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold;

use InvalidArgumentException;

/**
 * One file the generator would write, by its path relative to the project root.
 *
 * A modification carries the content the generator read, so that whoever applies the change can refuse it when the
 * file has changed since, and so that the change can be shown as a diff.
 */
final readonly class FileChange
{
    private function __construct(
        public string $path,
        public ChangeAction $action,
        public string $content,
        public ?string $original,
    ) {
        if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new InvalidArgumentException("A change must name a path inside the project, not '{$path}'.");
        }
    }

    public static function create(string $path, string $content): self
    {
        return new self($path, ChangeAction::Create, $content, null);
    }

    public static function modify(string $path, string $original, string $content): self
    {
        return new self($path, ChangeAction::Modify, $content, $original);
    }

    public function diff(): string
    {
        return UnifiedDiff::between($this->path, $this->original ?? '', $this->content, $this->action);
    }
}
