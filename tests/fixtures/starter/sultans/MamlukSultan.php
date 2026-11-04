<?php

declare(strict_types=1);

namespace Api\DataTransferObjects\v1;

use OpenApi\Attributes as OA;

/**
 * A DTO is the contract for one shape of data. The same class describes the response and, without the fields the
 * server sets, the request body; its attributes become a schema in the OpenAPI document.
 */
#[OA\Schema(schema: 'MamlukSultan', description: 'A sultan of the Mamluk Sultanate.')]
final readonly class MamlukSultan
{
    public function __construct(
        #[OA\Property(description: 'Identifier of the sultan.', example: 4)]
        public int $id,

        #[OA\Property(description: 'Name by which the sultan is known.', example: 'Baybars')]
        public string $name,

        #[OA\Property(description: 'Year the reign began (CE).', example: 1260)]
        public int $reignFrom,

        #[OA\Property(description: 'Year the reign ended (CE).', example: 1277)]
        public int $reignTo,
    ) {}
}
