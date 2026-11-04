<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Validate;

/**
 * One problem openapi:validate found: the check's stable identifier (OAS-001, KIP-003, ...), and the path, method,
 * schema or JSON pointer it concerns.
 */
final readonly class Finding
{
    public function __construct(
        public string $check,
        public Severity $severity,
        public string $location,
        public string $message,
    ) {}

    /**
     * @return array{check: string, severity: string, location: string, message: string}
     */
    public function toArray(): array
    {
        return ['check' => $this->check, 'severity' => $this->severity->value, 'location' => $this->location, 'message' => $this->message];
    }
}
