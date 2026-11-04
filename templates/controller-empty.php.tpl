<?php

declare(strict_types=1);

namespace {{namespace}};

use Kipchak\Core\Components\Controllers;
use OpenApi\Attributes as OA;

/**
 * Controllers are thin: they read the request, call a model and return a response. Each operation's attributes
 * describe it in the OpenAPI document served at /openapi.json, and refer to DTO schemas rather than defining them.
 *
 * Every controller extending Controllers\Base has the DI container in $this->container and the Logger driver's
 * logger in $this->logger.
 */
#[OA\Tag(name: '{{controller}}', description: '{{tagDescription}}')]
final class {{controller}} extends Controllers\Base
{
}
