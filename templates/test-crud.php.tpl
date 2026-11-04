
    public function testCreatesA{{singular}}(): void
    {
        $response = $this->send('POST', '{{path}}', {{newRow}});

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(3, $this->json($response)['data']['id']);
    }

    public function testRefusesAnInvalid{{singular}}(): void
    {
        $response = $this->send('POST', '{{path}}', []);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('BAD_REQUEST', $this->json($response)['status']);
    }

    public function testReplacesA{{singular}}(): void
    {
        $response = $this->send('PUT', '{{path}}/1', {{newRow}});

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->json($response)['data']['id']);
    }

    public function testDeletesA{{singular}}(): void
    {
        self::assertSame(204, self::app()->handle(new ServerRequestFactory()->createServerRequest('DELETE', '{{path}}/1'))->getStatusCode());
        self::assertSame(404, self::app()->handle(new ServerRequestFactory()->createServerRequest('DELETE', '{{path}}/99'))->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body): ResponseInterface
    {
        $request = new ServerRequestFactory()->createServerRequest($method, $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new StreamFactory()->createStream(json_encode($body, JSON_THROW_ON_ERROR)));

        return self::app()->handle($request);
    }
