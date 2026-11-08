<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use InvalidArgumentException;

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
        // The model has the resource's name and imports the DTO, so one name for both is a class that does not compile.
        // PHP compares class names without regard to case.
        if (strcasecmp($this->dto, $controller) === 0) {
            $plural = self::pluralise($controller);
            throw new InvalidArgumentException(
                "{$controller} would name both the model and its DTO, which does not compile. Did you mean {$plural}? "
                . "To keep the name, give the DTO another one with --dto or --singular, such as --dto={$controller}Item."
            );
        }
        $this->singularWords = Field::words($this->singular);
        $this->pluralWords = Field::words($controller);
        $this->segment = str_replace(' ', '-', $this->pluralWords);
        $this->variable = lcfirst($this->singular);
        $this->constant = strtoupper(str_replace(' ', '_', $this->pluralWords));
    }

    private static function pluralise(string $singular): string
    {
        return match (true) {
            (bool) preg_match('/[^aeiou]y$/', $singular) => substr($singular, 0, -1) . 'ies',
            (bool) preg_match('/(s|ch|sh|x)$/', $singular) => $singular . 'es',
            default => $singular . 's',
        };
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
