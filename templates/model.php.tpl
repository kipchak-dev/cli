<?php

declare(strict_types=1);

namespace {{namespace}};

use {{dtoClass}};
{{crudUses}}use CuyZ\Valinor\Mapper\Source\Source;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;

/**
 * Models hold the business logic. This one reads from an array; a real API would read from a database through a
 * driver, such as the Doctrine driver, and map the rows into the same DTO.
 */
final class {{model}}
{
    private const array {{constant}} = [
{{rows}}
    ];

    private TreeMapper $mapper;

    public function __construct()
    {
        $this->mapper = (new MapperBuilder())->mapper();
    }

    /**
     * @return list<{{dto}}>
     */
    public function all(): array
    {
        return array_map($this->map(...), self::{{constant}});
    }

    public function find(int $id): ?{{dto}}
    {
        foreach (self::{{constant}} as ${{variable}}) {
            if (${{variable}}['id'] === $id) {
                return $this->map(${{variable}});
            }
        }

        return null;
    }
{{crudMethods}}
    /**
     * @param array<string, mixed> ${{variable}}
     */
    private function map(array ${{variable}}): {{dto}}
    {
        return $this->mapper->map({{dto}}::class, Source::array(${{variable}}));
    }
}
