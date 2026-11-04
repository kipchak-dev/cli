<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold;

use InvalidArgumentException;

/**
 * What a generator returns: the files it would write, in order, and messages for any step it would not take itself,
 * such as a line to add by hand to a file whose shape it does not recognise.
 */
final readonly class ChangeSet
{
    /**
     * @param list<FileChange> $changes
     * @param list<string> $messages
     * @param list<array{file: string, key: string}> $settings values the developer must supply, such as API keys
     */
    public function __construct(
        public array $changes = [],
        public array $messages = [],
        public array $settings = [],
    ) {
        $paths = array_map(static fn (FileChange $change): string => $change->path, $changes);
        if (count($paths) !== count(array_unique($paths))) {
            throw new InvalidArgumentException('A change set may change each file only once.');
        }
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * The machine-readable form documented on /docs/cli (SR-2026-012 §3.4). Fields are never removed or renamed
     * within a major version.
     *
     * @return array{changes: list<array{path: string, action: string, content: string, diff: string}>, messages: list<string>, settings: list<array{file: string, key: string}>}
     */
    public function toArray(): array
    {
        return [
            'changes' => array_map(static fn (FileChange $change): array => [
                'path' => $change->path,
                'action' => $change->action->value,
                'content' => $change->content,
                'diff' => $change->diff(),
            ], $this->changes),
            'messages' => $this->messages,
            'settings' => $this->settings,
        ];
    }
}
