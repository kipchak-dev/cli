<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

/**
 * The names a resource's files use, from its plural class name: Orders gives the DTO Order, the variable $order, the
 * constant ORDERS and the path segment orders.
 */
final readonly class Resource
{
    public string $singular;
    public string $dto;
    public string $singularWords;
    public string $pluralWords;
    public string $segment;
    public string $variable;
    public string $constant;

    public function __construct(
        public string $controller,
        ?string $dto = null,
        ?string $singular = null,
        public bool $crud = false,
    ) {
        Names::className($controller, 'resource');
        $this->singular = Names::className($singular ?? self::singularise($controller), 'singular');
        $this->dto = Names::className($dto ?? $this->singular, 'DTO');
        $this->singularWords = Field::words($this->singular);
        $this->pluralWords = Field::words($controller);
        $this->segment = str_replace(' ', '-', $this->pluralWords);
        $this->variable = lcfirst($this->singular);
        $this->constant = strtoupper(str_replace(' ', '_', $this->pluralWords));
    }

    private static function singularise(string $plural): string
    {
        return match (true) {
            str_ends_with($plural, 'ies') => substr($plural, 0, -3) . 'y',
            (bool) preg_match('/(ss|ch|sh|x)es$/', $plural) => substr($plural, 0, -2),
            str_ends_with($plural, 's') && !str_ends_with($plural, 'ss') => substr($plural, 0, -1),
            default => $plural,
        };
    }
}
