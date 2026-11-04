<?php

declare(strict_types=1);

namespace Tests;

final class SultansTest extends ApiTestCase
{
    public function testListsTheSultans(): void
    {
        $response = $this->request('GET', '/v1/sultans');
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['code' => 200, 'status' => 'OK'], array_slice($body, 0, 2));
        self::assertCount(5, $body['data']);
        self::assertSame(['id' => 4, 'name' => 'Baybars', 'reignFrom' => 1260, 'reignTo' => 1277], $body['data'][3]);
    }

    public function testGetsOneSultan(): void
    {
        $response = $this->request('GET', '/v1/sultans/3');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Qutuz', $this->json($response)['data']['name']);
    }

    public function testAnUnknownSultanIsNotFound(): void
    {
        $response = $this->request('GET', '/v1/sultans/99');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            ['code' => 404, 'status' => 'RESOURCE_NOT_FOUND', 'data' => 'No sultan has the ID 99.'],
            $this->json($response),
        );
    }

    public function testTheStatusCheckAnswers(): void
    {
        $response = $this->request('GET', '/status');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'alive'], $this->json($response)['data']);
    }
}
