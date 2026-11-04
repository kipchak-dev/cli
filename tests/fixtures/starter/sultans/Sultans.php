<?php

declare(strict_types=1);

namespace Api\Models;

use Api\DataTransferObjects\v1\MamlukSultan;
use CuyZ\Valinor\Mapper\Source\Source;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;

/**
 * Models hold the business logic. This one reads from an array; a real API would read from a database through a
 * driver, such as the Doctrine driver, and map the rows into the same DTO.
 */
final class Sultans
{
    private const array SULTANS = [
        ['id' => 1, 'name' => 'Aybak', 'reignFrom' => 1250, 'reignTo' => 1257],
        ['id' => 2, 'name' => 'Al-Mansur Ali', 'reignFrom' => 1257, 'reignTo' => 1259],
        ['id' => 3, 'name' => 'Qutuz', 'reignFrom' => 1259, 'reignTo' => 1260],
        ['id' => 4, 'name' => 'Baybars', 'reignFrom' => 1260, 'reignTo' => 1277],
        ['id' => 5, 'name' => 'Qalawun', 'reignFrom' => 1279, 'reignTo' => 1290],
    ];

    private TreeMapper $mapper;

    public function __construct()
    {
        $this->mapper = (new MapperBuilder())->mapper();
    }

    /**
     * @return list<MamlukSultan>
     */
    public function all(): array
    {
        return array_map($this->map(...), self::SULTANS);
    }

    public function find(int $id): ?MamlukSultan
    {
        foreach (self::SULTANS as $sultan) {
            if ($sultan['id'] === $id) {
                return $this->map($sultan);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sultan
     */
    private function map(array $sultan): MamlukSultan
    {
        return $this->mapper->map(MamlukSultan::class, Source::array($sultan));
    }
}
