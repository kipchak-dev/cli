<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Scaffold\Validate;

use Kipchak\Cli\Scaffold\Validate\Finding;
use Kipchak\Cli\Scaffold\Validate\Inspection;
use Kipchak\Cli\Scaffold\Validate\OpenApiChecks;
use PHPUnit\Framework\TestCase;

/**
 * The checks of openapi:validate on the starter's own inspection (tests/fixtures/validate/starter-inspection.json,
 * written by resources/inspect.php from kipchak/starter 4.3), with one defect added per check. The same defects in
 * a real project are in OpenApiValidateFixtureTest.
 */
final class OpenApiChecksTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function starter(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/validate/starter-inspection.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $inspection
     * @param list<string> $ignoredRoutes
     * @return list<Finding>
     */
    private static function check(array $inspection, array $ignoredRoutes = []): array
    {
        return new OpenApiChecks()->check(Inspection::fromJson((string) json_encode($inspection, JSON_PRESERVE_ZERO_FRACTION)), $ignoredRoutes);
    }

    /**
     * @param list<Finding> $findings
     * @return list<string> 'CHECK location' for each finding
     */
    private static function summary(array $findings): array
    {
        return array_map(static fn (Finding $f): string => "{$f->check} {$f->location}", $findings);
    }

    public function testTheStarterHasNoFinding(): void
    {
        // Not even a warning: a new project passes openapi:validate --strict.
        self::assertSame([], self::summary(self::check(self::starter())));
    }

    /**
     * One defect per check, made in the starter's inspection.
     *
     * @return array<string, array{0: string, 1: callable(array<string, mixed>): array<string, mixed>, 2: string}>
     */
    public static function defects(): array
    {
        return [
            'an invalid parameter style' => ['OAS-001', static function (array $i): array {
                $i['document']['paths']['/v1/sultans/{id}']['get']['parameters'][0]['style'] = 'form';

                return $i;
            }, '/paths/~1v1~1sultans~1{id}/get/parameters/0/style'],
            'a swagger-php warning' => ['OAS-002', static function (array $i): array {
                $i['warnings'][] = '@OA\Get() requires at least one @OA\Response()';

                return $i;
            }, 'swagger-php'],
            'a reference to nothing' => ['OAS-003', static function (array $i): array {
                $i['document']['paths']['/v1/sultans/{id}']['get']['responses']['404']['$ref'] = '#/components/responses/Missing';

                return $i;
            }, '/paths/~1v1~1sultans~1{id}/get/responses/404'],
            'a route with no operation' => ['KIP-001', static function (array $i): array {
                $i['routes'][] = ['methods' => ['GET'], 'pattern' => '/v1/unlisted', 'handler' => 'Closure', 'name' => null];

                return $i;
            }, 'GET /v1/unlisted'],
            'an operation with no route' => ['KIP-001', static function (array $i): array {
                $i['routes'] = array_values(array_filter($i['routes'], static fn (array $r): bool => $r['pattern'] !== '/status'));

                return $i;
            }, 'GET /status'],
            'an undeclared path parameter' => ['KIP-002', static function (array $i): array {
                unset($i['document']['paths']['/v1/sultans/{id}']['get']['parameters']);

                return $i;
            }, 'GET /v1/sultans/{id}'],
            'no summary' => ['KIP-003', static function (array $i): array {
                unset($i['document']['paths']['/v1/sultans']['get']['summary']);

                return $i;
            }, 'GET /v1/sultans'],
            'a repeated operationId' => ['KIP-003', static function (array $i): array {
                $i['document']['paths']['/v1/sultans']['get']['operationId'] = $i['document']['paths']['/v1/sultans/{id}']['get']['operationId'];

                return $i;
            }, 'GET /v1/sultans/{id}'],
            'an inline request body' => ['KIP-004', static function (array $i): array {
                $i['document']['paths']['/v1/sultans']['post'] = $i['document']['paths']['/v1/sultans']['get'] + [
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]]],
                ];
                $i['document']['paths']['/v1/sultans']['post']['operationId'] = 'createSultan';
                $i['routes'][] = ['methods' => ['POST'], 'pattern' => '/v1/sultans', 'handler' => '', 'name' => null];

                return $i;
            }, 'POST /v1/sultans request body (application/json)'],
            'a property with no example' => ['KIP-005', static function (array $i): array {
                unset($i['document']['components']['schemas']['MamlukSultan']['properties']['name']['example']);

                return $i;
            }, 'schema MamlukSultan, property name'],
            'a success documented as its data alone' => ['KIP-006', static function (array $i): array {
                $i['document']['paths']['/v1/sultans/{id}']['get']['responses']['200']['content']['application/json']['schema'] = ['$ref' => '#/components/schemas/MamlukSultan'];

                return $i;
            }, 'GET /v1/sultans/{id} response 200 (application/json)'],
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $defect
     * @dataProvider defects
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('defects')]
    public function testEachDefectIsReportedByItsCheck(string $check, callable $defect, string $location): void
    {
        $starter = self::summary(self::check(self::starter()));
        $added = array_values(array_diff(self::summary(self::check($defect(self::starter()))), $starter));

        self::assertContains("{$check} {$location}", $added);
        // Nothing but the check concerned reports the defect.
        self::assertSame([$check], array_values(array_unique(array_map(static fn (string $s): string => strtok($s, ' '), $added))));
    }

    public function testWhatIsNotSentInTheEnvelopeIsNotReportedByKip006(): void
    {
        $bare = static function (array $i): array {
            $i['document']['paths']['/v1/sultans/{id}']['get']['responses']['200']['content']['application/json']['schema'] = ['$ref' => '#/components/schemas/MamlukSultan'];

            return $i;
        };
        $kip006 = static fn (array $i): array => array_values(array_filter(self::summary(self::check($i)), static fn (string $s): bool => str_starts_with($s, 'KIP-006')));

        self::assertSame(['KIP-006 GET /v1/sultans/{id} response 200 (application/json)'], $kip006($bare(self::starter())), 'Control');

        $optedOutResponse = $bare(self::starter());
        $optedOutResponse['document']['paths']['/v1/sultans/{id}']['get']['responses']['200']['x-kipchak-envelope'] = false;
        self::assertSame([], $kip006($optedOutResponse), 'x-kipchak-envelope: false on the response');

        $optedOutOperation = $bare(self::starter());
        $optedOutOperation['document']['paths']['/v1/sultans/{id}']['get']['x-kipchak-envelope'] = false;
        self::assertSame([], $kip006($optedOutOperation), 'x-kipchak-envelope: false on the operation');

        $notJson = $bare(self::starter());
        $responses = &$notJson['document']['paths']['/v1/sultans/{id}']['get']['responses'];
        $responses['200']['content'] = ['text/csv' => ['schema' => ['type' => 'string']]];
        unset($responses);
        self::assertSame([], $kip006($notJson), 'Not JSON');

        $starter44 = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/validate/starter-4.4-inspection.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], self::summary(self::check($starter44)), 'Starter 4.4: the Envelope processor states each success through allOf');

        // Envelope leaves a route marked x-kipchak-envelope: false as declared, and removes the marker.
        $starter44['document']['paths']['/v1/raw']['get'] = ['operationId' => 'raw', 'summary' => 'Passed on', 'tags' => ['Sultans'], 'responses' => [
            '200' => ['description' => 'The body of another service.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/MamlukSultan']]]],
        ]];
        $starter44['routes'][] = ['methods' => ['GET'], 'pattern' => '/v1/raw', 'handler' => '', 'name' => null];
        self::assertSame([], self::summary(self::check($starter44)), 'A route Envelope left alone on purpose');

        $shared = $bare(self::starter());
        $shared['document']['components']['responses']['Sultan'] = $shared['document']['paths']['/v1/sultans']['get']['responses']['200'];
        $shared['document']['paths']['/v1/sultans/{id}']['get']['responses']['200'] = ['$ref' => '#/components/responses/Sultan'];
        self::assertSame([], $kip006($shared), 'A shared response that states the envelope');
    }

    public function testAnIgnoredRouteIsNotReported(): void
    {
        $inspection = self::starter();
        $inspection['routes'][] = ['methods' => ['GET'], 'pattern' => '/internal/health', 'handler' => '', 'name' => null];

        self::assertContains('KIP-001 GET /internal/health', self::summary(self::check($inspection, [])));
        self::assertNotContains('KIP-001 GET /internal/health', self::summary(self::check($inspection, ['GET  /internal/health'])));
    }

    public function testWithoutRoutesTheRouteChecksDoNotRun(): void
    {
        $inspection = self::starter();
        unset($inspection['routes']);
        unset($inspection['document']['paths']['/v1/sultans/{id}']['get']['parameters']);

        self::assertSame([], self::summary(self::check($inspection)));
    }

    public function testAnUnsupportedVersionIsReported(): void
    {
        $inspection = self::starter();
        $inspection['document']['openapi'] = '2.0';

        self::assertContains('OAS-001 /openapi', self::summary(self::check($inspection)));
    }

    public function testAThreePointOneDocumentIsValidated(): void
    {
        $inspection = self::starter();
        $inspection['document']['openapi'] = '3.1.0';

        self::assertSame([], self::summary(self::check($inspection)));

        unset($inspection['document']['info']);
        self::assertContains('OAS-001 /', self::summary(self::check($inspection)));
    }

    public function testRoutePatternsBecomeDocumentPaths(): void
    {
        self::assertSame(['/v1/sultans/{id}'], OpenApiChecks::documentPaths('/v1/sultans/{id:[0-9]+}'));
        self::assertSame(['/v1/years/{year}'], OpenApiChecks::documentPaths('/v1/years/{year:[0-9]{4}}'));
        self::assertSame(['/v1/sultans', '/v1/sultans/{id}', '/v1/sultans/{id}/{tab}'], OpenApiChecks::documentPaths('/v1/sultans[/{id:\d+}[/{tab}]]'));
    }
}
