
    #[OA\Post(
        path: '{{path}}',
        operationId: 'create{{singular}}',
        summary: 'Create a {{singularWords}}',
        tags: ['{{controller}}'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/{{dto}}')),
        responses: [
            new OA\Response(
                response: 201,
                description: 'The {{singularWords}}, as created.',
{{createdContent}}
            ),
            new OA\Response(response: 400, description: 'The body is not valid JSON or does not match the {{dto}} schema; meta.errors lists every problem by field.'),
        ],
    )]
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        ${{variable}} = (new {{controller}}Model())->create(Input::from($request, {{dto}}::class, ['id' => 0]));

        return Http\Response::json($response, ${{variable}}, 201);
    }

    #[OA\Put(
        path: '{{path}}/{id}',
        operationId: 'update{{singular}}',
        summary: 'Replace a {{singularWords}}',
        tags: ['{{controller}}'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/{{dto}}')),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The {{singularWords}}, as replaced.',
{{itemContent}}
            ),
            new OA\Response(response: 400, description: 'The body is not valid JSON or does not match the {{dto}} schema; meta.errors lists every problem by field.'),
{{notFound}}
        ],
    )]
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        ${{variable}} = (new {{controller}}Model())->update((int) $args['id'], Input::from($request, {{dto}}::class, ['id' => (int) $args['id']]));
        if (${{variable}} === null) {
            throw new HttpNotFoundException($request, "No {{singularWords}} has the ID {$args['id']}.");
        }

        return Http\Response::json($response, ${{variable}}, 200);
    }

    #[OA\Delete(
        path: '{{path}}/{id}',
        operationId: 'delete{{singular}}',
        summary: 'Delete a {{singularWords}}',
        tags: ['{{controller}}'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 204, description: 'The {{singularWords}} was deleted.'),
{{notFound}}
        ],
    )]
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!(new {{controller}}Model())->delete((int) $args['id'])) {
            throw new HttpNotFoundException($request, "No {{singularWords}} has the ID {$args['id']}.");
        }

        return $response->withStatus(204);
    }
