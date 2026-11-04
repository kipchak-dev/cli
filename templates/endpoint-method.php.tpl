    #[{{OA}}\{{attribute}}(
        path: '{{openApiPath}}',
        operationId: '{{operationId}}',
        summary: '{{summary}}',
        tags: ['{{tag}}'],
{{parameters}}{{requestBody}}        responses: [
{{responses}}        ],
    )]
    public function {{action}}({{Request}} $request, {{Response}} $response, array $args): {{Response}}
    {
        // Replace this with the operation: read the request, call a model and return the result with
        // Http\Response::json($response, $result, {{status}}). Until then it answers 501 Not Implemented.
{{hints}}        throw new {{NotImplemented}}($request, '{{method}} {{openApiPath}} is not implemented yet.');
    }
