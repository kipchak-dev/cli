<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Add;

/**
 * A Kipchak package's extra.kipchak block (SR-2026-012 §3.3.6): how `kipchak add` installs it.
 */
final readonly class PackageMetadata
{
    /**
     * @param list<string> $after packages that, when initialised, must come before this one in the file
     * @param list<string> $before packages that, when initialised, must come after this one in the file
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $config,
        public ?string $configName,
        public string $initialise,
        public array $after,
        public array $before,
        public string $docs,
        public ?string $note = null,
    ) {}

    /**
     * From a package's composer.json, or its entry in vendor/composer/installed.json. Null when it declares none.
     *
     * @param array<string, mixed> $composer
     */
    public static function fromComposer(array $composer): ?self
    {
        $meta = $composer['extra']['kipchak'] ?? null;
        if (!is_array($meta) || !isset($composer['name'], $meta['type'], $meta['initialise'])) {
            return null;
        }

        return new self(
            (string) $composer['name'],
            (string) $meta['type'],
            isset($meta['config']) ? (string) $meta['config'] : null,
            isset($meta['configName']) ? (string) $meta['configName'] : null,
            (string) $meta['initialise'],
            array_values(array_map('strval', $meta['after'] ?? [])),
            array_values(array_map('strval', $meta['before'] ?? [])),
            (string) ($meta['docs'] ?? 'https://kipchak.dev'),
            isset($meta['note']) ? (string) $meta['note'] : null,
        );
    }

    public function isMiddleware(): bool
    {
        return $this->type === 'middleware';
    }

    /**
     * The file this package is initialised in.
     */
    public function registrationFile(): string
    {
        return $this->isMiddleware() ? 'middlewares/middlewares.php' : 'drivers/drivers.php';
    }

    /**
     * The fully qualified class whose initialise() the statement calls, without a leading backslash.
     */
    public function className(): string
    {
        if (preg_match('/^\\\\?([A-Za-z_][\w\\\\]*)::initialise\(/', $this->initialise, $matches) !== 1) {
            throw new \UnexpectedValueException("{$this->name} declares an initialise statement kipchak cannot read: {$this->initialise}");
        }

        return $matches[1];
    }

    /**
     * The argument passed to initialise(): $container for drivers, $app for middleware.
     */
    public function argument(): string
    {
        return $this->isMiddleware() ? '$app' : '$container';
    }
}
