<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Add;

/**
 * The result of placing one initialise() statement: the updated file, or null when nothing changes.
 */
final readonly class Registration
{
    /**
     * @param list<string> $messages
     */
    private function __construct(
        public ?string $content,
        public array $messages,
    ) {}

    /**
     * @param list<string> $messages
     */
    public static function changed(string $content, array $messages = []): self
    {
        return new self($content, $messages);
    }

    public static function unchanged(string $message): self
    {
        return new self(null, [$message]);
    }
}
