
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
            new OA\Response(response: 400, description: 'The body is not valid JSON or does not match the {{dto}} schema.'),
        ],
    )]
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            ${{variable}} = (new {{controller}}Model())->create($this->input($request));
        } catch (MappingError $error) {
            throw new HttpBadRequestException($request, $this->problems($error));
        }

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
            new OA\Response(response: 400, description: 'The body is not valid JSON or does not match the {{dto}} schema.'),
{{notFound}}
        ],
    )]
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            ${{variable}} = (new {{controller}}Model())->update((int) $args['id'], $this->input($request));
        } catch (MappingError $error) {
            throw new HttpBadRequestException($request, $this->problems($error));
        }
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

    /**
     * The request body as an array, read once. An invalid body is answered with a 400 in the API's error envelope.
     *
     * @return array<string, mixed>
     */
    private function input(ServerRequestInterface $request): array
    {
        try {
            $input = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpBadRequestException($request, 'The body is not valid JSON.');
        }
        if (!is_array($input)) {
            throw new HttpBadRequestException($request, 'The body must be a JSON object.');
        }

        return $input;
    }

    /**
     * What is wrong with a body that does not match the DTO, one sentence per problem.
     */
    private function problems(MappingError $error): string
    {
        $problems = array_map(static fn ($message): string => (string) $message, iterator_to_array($error->messages()->errors()));

        return 'The {{singularWords}} is not valid: ' . implode(' ', $problems);
    }
