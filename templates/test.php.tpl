<?php

declare(strict_types=1);

namespace Tests;
{{crudUses}}
final class {{controller}}Test extends ApiTestCase
{
    public function testListsThe{{controller}}(): void
    {
        $response = $this->request('GET', '{{path}}');
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['code' => 200, 'status' => 'OK'], array_slice($body, 0, 2));
        self::assertCount(2, $body['data']);
        self::assertSame({{secondRow}}, $body['data'][1]);
    }

    public function testGetsOne{{singular}}(): void
    {
        $response = $this->request('GET', '{{path}}/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame({{firstValue}}, $this->json($response)['data']['{{firstField}}']);
    }

    public function testAnUnknown{{singular}}IsNotFound(): void
    {
        $response = $this->request('GET', '{{path}}/99');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            ['code' => 404, 'status' => 'RESOURCE_NOT_FOUND', 'data' => 'No {{singularWords}} has the ID 99.'],
            $this->json($response),
        );
    }
{{crudTests}}}
