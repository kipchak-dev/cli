<?php

declare(strict_types=1);

namespace {{namespace}};

{{uses}}

/**
 * A DTO is the contract for one shape of data. The same class describes the response and, without the fields the
 * server sets, the request body; its attributes become a schema in the OpenAPI document.
 */
#[OA\Schema(schema: '{{dto}}', description: '{{schemaDescription}}')]
final readonly class {{dto}}
{
{{constructorDoc}}    public function __construct(
{{properties}}
    ) {}
}
