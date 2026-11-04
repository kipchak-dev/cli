<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold;

/**
 * What a generator knows about a project: its PSR-4 namespaces, its kipchak/core version, and the contents of the
 * files a request may edit. The CLI builds it from disk; the MCP server (KIP-X2) builds it from what an assistant
 * supplies. Generators never read the filesystem themselves.
 */
final readonly class Project
{
    /**
     * @param array<string, string> $namespaces PSR-4 prefix (with trailing backslash) => directory (with trailing slash)
     * @param array<string, string> $files path relative to the project root => content
     */
    public function __construct(
        public array $namespaces,
        public string $coreVersion,
        public array $files = [],
    ) {}

    public function coreMajor(): int
    {
        return (int) explode('.', ltrim($this->coreVersion, 'v'))[0];
    }

    public function file(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function hasFile(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    /**
     * The namespace of a directory, from the PSR-4 map: 'api/Controllers/v1' gives 'Api\Controllers\v1' in the
     * starter. Null when no PSR-4 root contains the directory.
     */
    public function namespaceFor(string $directory): ?string
    {
        $directory = trim($directory, '/') . '/';
        $best = null;
        foreach ($this->namespaces as $prefix => $root) {
            $root = trim($root, '/') . '/';
            if (str_starts_with($directory, $root) && ($best === null || strlen($root) > strlen($best[1]))) {
                $best = [$prefix, $root];
            }
        }
        if ($best === null) {
            return null;
        }

        $relative = substr($directory, strlen($best[1]));

        return rtrim($best[0] . str_replace('/', '\\', $relative), '\\');
    }
}
