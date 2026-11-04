<?php

declare(strict_types=1);

namespace Kipchak\Cli\Scaffold\Make;

use InvalidArgumentException;

/**
 * One field of a DTO, from `--fields`: `name:type`, where type is int, float, string, bool, datetime, another DTO's
 * name, or `Name[]` for a list of another DTO. A leading `?` on the type makes the field nullable.
 */
final readonly class Field
{
    private const array SCALARS = ['int', 'float', 'string', 'bool', 'datetime'];

    private function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public bool $list,
    ) {}

    public static function parse(string $definition): self
    {
        if (preg_match('/^([a-z][A-Za-z0-9]*):(\??)([A-Za-z][A-Za-z0-9]*)(\[\])?$/', trim($definition), $m) !== 1) {
            throw new InvalidArgumentException(
                "'{$definition}' is not a field. Write name:type, such as reference:string, total:float, placedAt:datetime, "
                . 'note:?string or items:LineItem[].'
            );
        }
        [, $name, $nullable, $type, $list] = $m + [4 => ''];
        $scalar = in_array(strtolower($type), self::SCALARS, true);
        if ($list !== '' && $scalar) {
            throw new InvalidArgumentException("'{$definition}': a list must be of another DTO, such as items:LineItem[].");
        }
        if (!$scalar && preg_match('/^[A-Z]/', $type) !== 1) {
            throw new InvalidArgumentException("'{$definition}': '{$type}' is not a type. Use int, float, string, bool, datetime or a DTO name.");
        }

        return new self($name, $scalar ? strtolower($type) : $type, $nullable === '?', $list !== '');
    }

    /**
     * @return list<self>
     */
    public static function parseList(string $definitions): array
    {
        $fields = array_map(self::parse(...), array_filter(array_map('trim', explode(',', $definitions)), 'strlen'));
        $names = array_map(static fn (self $field): string => $field->name, $fields);
        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('Each field may be given only once.');
        }

        return array_values($fields);
    }

    public function isDto(): bool
    {
        return !in_array($this->type, self::SCALARS, true);
    }

    /**
     * The PHP type of the promoted property.
     */
    public function phpType(): string
    {
        $type = match (true) {
            $this->list => 'array',
            $this->type === 'datetime' => 'DateTimeImmutable',
            default => $this->type,
        };

        return ($this->nullable ? '?' : '') . $type;
    }

    /**
     * The arguments of its #[OA\Property] after the description.
     */
    public function openApiArguments(): string
    {
        return match (true) {
            $this->list => "type: 'array', items: new OA\\Items(ref: '#/components/schemas/{$this->type}')",
            $this->isDto() => "ref: '#/components/schemas/{$this->type}'",
            $this->type === 'datetime' => "format: 'date-time', example: '" . self::DATETIME_EXAMPLE . "'",
            default => 'example: ' . $this->examplePhp(),
        } . ($this->nullable ? ', nullable: true' : '');
    }

    public const string DATETIME_EXAMPLE = '2026-01-31T09:00:00+00:00';

    /**
     * The example value as PHP source, for the DTO attribute and the model's example rows.
     */
    public function examplePhp(int $row = 1): string
    {
        return match ($this->type) {
            'int' => (string) ($this->name === 'id' ? $row : 2025 + $row),
            // A fractional part, so the value survives json_encode() as a float: 19.0 would be encoded as 19.
            'float' => number_format(9.5 + 10 * ($row - 1), 1, '.', ''),
            'string' => var_export(ucfirst(self::words($this->name)) . " {$row}", true),
            'bool' => $row === 1 ? 'true' : 'false',
            'datetime' => var_export(self::DATETIME_EXAMPLE, true),
            default => $this->list ? '[]' : 'null',
        };
    }

    /**
     * The field's name as words: placedAt gives "placed at".
     */
    public static function words(string $name): string
    {
        return strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name)));
    }
}
