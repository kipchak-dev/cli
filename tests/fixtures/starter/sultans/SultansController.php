<?php

declare(strict_types=1);

namespace Api\Controllers\v1;

use Api\Models\Sultans as SultansModel;
use Kipchak\Core\Components\Controllers;
use Kipchak\Core\Components\Http;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Controllers are thin: they read the request, call a model and return a response. Each operation's attributes
 * describe it in the OpenAPI document served at /openapi.json, and refer to DTO schemas rather than defining them.
 *
 * Every controller extending Controllers\Base has the DI container in $this->container and the Logger driver's
 * logger in $this->logger.
 */
#[OA\Tag(name: 'Sultans', description: 'The sultans of the Mamluk Sultanate.')]
final class Sultans extends Controllers\Base
{
    #[OA\Get(
        path: '/v1/sultans',
        operationId: 'listSultans',
        summary: 'List the sultans',
        tags: ['Sultans'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Every sultan, in order of reign.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'code', type: 'integer', example: 200),
                    new OA\Property(property: 'status', type: 'string', example: 'OK'),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MamlukSultan')),
                ]),
            ),
        ],
    )]
    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Http\Response::json($response, (new SultansModel())->all(), 200);
    }

    #[OA\Get(
        path: '/v1/sultans/{id}',
        operationId: 'getSultan',
        summary: 'Get one sultan',
        tags: ['Sultans'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 4)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The sultan.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'code', type: 'integer', example: 200),
                    new OA\Property(property: 'status', type: 'string', example: 'OK'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/MamlukSultan'),
                ]),
            ),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
        ],
    )]
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sultan = (new SultansModel())->find((int) $args['id']);
        if ($sultan === null) {
            // The Error middleware turns this into a 404 with the same envelope as every other response.
            throw new HttpNotFoundException($request, "No sultan has the ID {$args['id']}.");
        }

        return Http\Response::json($response, $sultan, 200);
    }
}
