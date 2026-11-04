<?php

declare(strict_types=1);

namespace {{namespace}};

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 middleware. {{registration}}
 *
 * One instance serves every request the worker handles, so keep nothing about a request in a property: pass what
 * later middleware and controllers need with $request->withAttribute(). Refuse a request by throwing a Slim HTTP
 * exception, such as HttpForbiddenException, so that the Error middleware answers it in the API's JSON envelope.
 */
final readonly class {{name}} implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Before the route: read, change or refuse the request.

        $response = $handler->handle($request);

        // After the route: read or change the response.

        return $response;
    }
}
