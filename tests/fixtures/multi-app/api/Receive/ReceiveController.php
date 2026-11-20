<?php

declare(strict_types=1);

namespace Api\Receive;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The second app's operations, in the same OpenAPI document as the main app's.
 */
final class ReceiveController
{
    #[OA\Put(
        path: '/receive/v1/files/{name}',
        operationId: 'putReceivedFile',
        summary: 'Receive one file',
        tags: ['Receive'],
        parameters: [
            new OA\Parameter(name: 'name', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'report.json')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'The file was received.'),
        ],
    )]
    public function put(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $response->withStatus(204);
    }

    #[OA\Get(
        path: '/receive/v1/health',
        operationId: 'getReceiveHealth',
        summary: 'Check that the receive app is running',
        tags: ['Receive'],
        responses: [
            new OA\Response(response: 204, description: 'The receive app is running.'),
        ],
    )]
    public function health(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $response->withStatus(204);
    }
}
