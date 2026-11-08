<?php

declare(strict_types=1);

namespace {{namespace}};

use {{modelClass}} as {{controller}}Model;
{{crudUsesBefore}}use Kipchak\Core\Components\Controllers;
use Kipchak\Core\Components\Http;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
{{crudUsesAfter}}use Slim\Exception\HttpNotFoundException;

/**
 * Controllers are thin: they read the request, call a model and return a response. Each operation's attributes
 * describe it in the OpenAPI document served at /openapi.json, and refer to DTO schemas rather than defining them.
{{envelopeNote}} *
 * Every controller extending Controllers\Base has the DI container in $this->container and the Logger driver's
 * logger in $this->logger.
 */
#[OA\Tag(name: '{{controller}}', description: '{{tagDescription}}')]
final class {{controller}} extends Controllers\Base
{
    #[OA\Get(
        path: '{{path}}',
        operationId: 'list{{controller}}',
        summary: 'List the {{pluralWords}}',
        tags: ['{{controller}}'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Every {{singularWords}}.',
{{listContent}}
            ),
        ],
    )]
    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Http\Response::json($response, (new {{controller}}Model())->all(), 200);
    }

    #[OA\Get(
        path: '{{path}}/{id}',
        operationId: 'get{{singular}}',
        summary: 'Get one {{singularWords}}',
        tags: ['{{controller}}'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The {{singularWords}}.',
{{itemContent}}
            ),
{{notFound}}
        ],
    )]
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        ${{variable}} = (new {{controller}}Model())->find((int) $args['id']);
        if (${{variable}} === null) {
            // The Error middleware turns this into a 404 with the same envelope as every other response.
            throw new HttpNotFoundException($request, "No {{singularWords}} has the ID {$args['id']}.");
        }

        return Http\Response::json($response, ${{variable}}, 200);
    }
{{crudMethods}}}
