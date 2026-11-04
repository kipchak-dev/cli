<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Scaffold\Make;

use InvalidArgumentException;
use Kipchak\Cli\Scaffold\ChangeAction;
use Kipchak\Cli\Scaffold\ChangeSet;
use Kipchak\Cli\Scaffold\Make\Endpoint;
use Kipchak\Cli\Scaffold\Make\MakeGenerators;
use Kipchak\Cli\Scaffold\Project;
use Kipchak\Cli\Scaffold\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * make:endpoint edits two existing files by inserting lines, and leaves every other line as it was (SR-2026-012
 * §3.3.4).
 */
final class EndpointTest extends TestCase
{
    private const string STARTER = __DIR__ . '/../../fixtures/starter/sultans/';
    private const string CONTROLLER = 'api/Controllers/v1/Sultans.php';
    private const string ROUTES = 'routes/v1/sultans.php';

    /**
     * @param array<string, string> $files
     */
    private function generate(Endpoint $endpoint, array $files = []): ChangeSet
    {
        $files += [
            self::CONTROLLER => (string) file_get_contents(self::STARTER . 'SultansController.php'),
            self::ROUTES => (string) file_get_contents(self::STARTER . 'routes.php'),
        ];

        return new MakeGenerators()->endpoint(new Project(['Api\\' => 'api/'], '3.16', $files), $endpoint);
    }

    /**
     * @return array<string, string> path => new content
     */
    private function contents(ChangeSet $changeSet): array
    {
        $contents = [];
        foreach ($changeSet->changes as $change) {
            $contents[$change->path] = $change->content;
        }

        return $contents;
    }

    /**
     * The lines a change added, when every original line is kept in order.
     *
     * @return list<string>
     */
    private static function addedLines(string $original, string $changed): array
    {
        $before = explode("\n", $original);
        $added = [];
        $index = 0;
        foreach (explode("\n", $changed) as $line) {
            if ($index < count($before) && $line === $before[$index]) {
                $index++;
            } else {
                $added[] = $line;
            }
        }
        self::assertSame(count($before), $index, 'Every original line is kept, in order.');

        return $added;
    }

    public function testAddsTheRouteInsideTheStartersGroup(): void
    {
        $changeSet = $this->generate(new Endpoint('post', '/v1/sultans/{id:[0-9]+}/crown', 'Sultans::crown', 'MamlukSultan'));
        $routes = $this->contents($changeSet)[self::ROUTES];

        self::assertSame(
            ["    \$group->post('/sultans/{id:[0-9]+}/crown', [Controllers\\v1\\Sultans::class, 'crown']);"],
            self::addedLines((string) file_get_contents(self::STARTER . 'routes.php'), $routes),
        );
        self::assertStringContainsString("'crown']);\n});\n", $routes);
    }

    public function testAddsTheMethodAndOnlyTheImportItNeeds(): void
    {
        $original = (string) file_get_contents(self::STARTER . 'SultansController.php');
        $controller = $this->contents($this->generate(new Endpoint('POST', '/v1/sultans/{id:[0-9]+}/crown', 'Sultans::crown', 'MamlukSultan')))[self::CONTROLLER];
        $added = self::addedLines($original, $controller);

        // HttpNotImplementedException sorts after HttpNotFoundException, the starter's last import.
        self::assertSame('use Slim\\Exception\\HttpNotImplementedException;', $added[0]);
        self::assertSame(1, preg_match_all('/^use /m', implode("\n", $added)));
        self::assertStringContainsString("use Slim\\Exception\\HttpNotFoundException;\nuse Slim\\Exception\\HttpNotImplementedException;\n", $controller);
        self::assertStringContainsString("        path: '/v1/sultans/{id}/crown',", $controller);
        self::assertStringContainsString("        tags: ['Sultans'],", $controller);
        self::assertStringContainsString("schema: new OA\\Schema(type: 'integer')", $controller);
        self::assertStringContainsString("requestBody: new OA\\RequestBody(required: true, content: new OA\\JsonContent(ref: '#/components/schemas/MamlukSultan')),", $controller);
        self::assertStringContainsString("example: 201", $controller);
        self::assertStringContainsString("    }\n\n    #[OA\\Post(", $controller);
        self::assertStringEndsWith("is not implemented yet.');\n    }\n}\n", $controller);
        new SourceFile($controller); // parses
    }

    public function testAMethodOnAnEmptyControllerHasNoBlankLineAbove(): void
    {
        $controller = (string) new MakeGenerators()->controller(new Project(['Api\\' => 'api/'], '3.16'), 'Orders')->changes[0]->content;
        $changeSet = $this->generate(new Endpoint('GET', '/v1/orders', 'Orders::list'), ['api/Controllers/v1/Orders.php' => $controller]);
        $edited = $this->contents($changeSet)['api/Controllers/v1/Orders.php'];

        self::assertStringContainsString("final class Orders extends Controllers\\Base\n{\n    #[OA\\Get(\n", $edited);
        self::assertStringContainsString("description: 'The result.',", $edited);
        self::assertStringContainsString("property: 'data', type: 'object'", $edited);
        self::assertStringNotContainsString('parameters:', $edited);
        self::assertStringNotContainsString('requestBody:', $edited);
        // The empty controller imports neither PSR-7 interface; both go in alphabetical place.
        self::assertStringContainsString("use OpenApi\\Attributes as OA;\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Psr\\Http\\Message\\ServerRequestInterface;\nuse Slim\\Exception\\HttpNotImplementedException;\n", $edited);
        new SourceFile($edited);
    }

    public function testCreatesTheRouteFileWhenNoneRoutesTheController(): void
    {
        $controller = (string) new MakeGenerators()->controller(new Project(['Api\\' => 'api/'], '3.16'), 'Orders')->changes[0]->content;
        $changeSet = $this->generate(new Endpoint('DELETE', '/v1/orders/{reference}', 'Orders::cancel'), ['api/Controllers/v1/Orders.php' => $controller]);
        $routes = $changeSet->changes[1];

        self::assertSame('routes/v1/orders.php', $routes->path);
        self::assertSame(ChangeAction::Create, $routes->action);
        self::assertStringContainsString("use Api\\Controllers;\n", $routes->content);
        self::assertStringContainsString("\$app->group('/v1', function (RouteCollectorProxy \$group) {\n    \$group->delete('/orders/{reference}', [Controllers\\v1\\Orders::class, 'cancel']);\n});\n", $routes->content);
        self::assertStringContainsString("response: 204, description: 'Done.'", $changeSet->changes[0]->content);
        self::assertStringContainsString("schema: new OA\\Schema(type: 'string')", $changeSet->changes[0]->content);
    }

    public function testUsesTheRouteFileThatAlreadyRoutesTheController(): void
    {
        $court = "<?php\n\nuse Api\\Controllers;\n\n\$app->group('/v1', function (\$group) {\n    \$group->get('/sultans', [Controllers\\v1\\Sultans::class, 'list']);\n});\n";
        $changeSet = new MakeGenerators()->endpoint(new Project(['Api\\' => 'api/'], '3.16', [
            self::CONTROLLER => (string) file_get_contents(self::STARTER . 'SultansController.php'),
            'routes/v1/court.php' => $court,
        ]), new Endpoint('GET', '/v1/sultans/count', 'Sultans::count'));
        $contents = $this->contents($changeSet);

        // court.php routes Sultans, so the route goes there rather than in a new sultans.php.
        self::assertSame([self::CONTROLLER, 'routes/v1/court.php'], array_keys($contents));
        self::assertStringContainsString("    \$group->get('/sultans/count', [Controllers\\v1\\Sultans::class, 'count']);\n});", $contents['routes/v1/court.php']);
    }

    public function testAGroupWithMiddlewareIsStillFound(): void
    {
        $routes = "<?php\n\nuse Api\\Controllers;\n\n\$app->group('/v1', function (\$group) {\n\t\$group->get('/sultans', [Controllers\\v1\\Sultans::class, 'list']);\n})->add(new Api\\Middlewares\\RequireRole('admin'));\n";
        $edited = $this->contents($this->generate(new Endpoint('GET', '/v1/sultans/count', 'Sultans::count'), [self::ROUTES => $routes]))[self::ROUTES];

        // The new route takes the indentation of the routes already there, a tab here.
        self::assertStringContainsString("\t\$group->get('/sultans/count', [Controllers\\v1\\Sultans::class, 'count']);\n})->add(", $edited);
    }

    public function testARouteFileWithTwoGroupsIsNotEdited(): void
    {
        $routes = (string) file_get_contents(self::STARTER . 'routes.php');
        $twoGroups = $routes . "\n\$app->group('/v1', function (RouteCollectorProxy \$group) {\n});\n";
        $changeSet = $this->generate(new Endpoint('GET', '/v1/sultans/count', 'Sultans::count'), [self::ROUTES => $twoGroups]);

        self::assertArrayNotHasKey(self::ROUTES, $this->contents($changeSet));
        self::assertArrayHasKey(self::CONTROLLER, $this->contents($changeSet));
        self::assertStringContainsString(
            "routes/v1/sultans.php was not changed, because it has more than one \$app->group('/v1', function (...) { ... }) to add it to. "
            . "Add the route by hand: \$group->get('/sultans/count', [\\Api\\Controllers\\v1\\Sultans::class, 'count']);",
            $changeSet->messages[0],
        );
    }

    public function testARouteFileWithNoGroupForTheVersionIsNotEdited(): void
    {
        $routes = str_replace("group('/v1'", "group('/v2'", (string) file_get_contents(self::STARTER . 'routes.php'));
        $changeSet = $this->generate(new Endpoint('GET', '/v1/sultans/count', 'Sultans::count'), [self::ROUTES => $routes]);

        self::assertArrayNotHasKey(self::ROUTES, $this->contents($changeSet));
        self::assertStringContainsString('because it has no $app->group', $changeSet->messages[0]);
    }

    public function testAnExistingRouteIsNotAddedTwice(): void
    {
        $changeSet = $this->generate(new Endpoint('GET', '/v1/sultans', 'Sultans::all'));

        self::assertArrayNotHasKey(self::ROUTES, $this->contents($changeSet));
        self::assertStringContainsString('because it already routes GET /v1/sultans.', $changeSet->messages[0]);
    }

    public function testAnExistingMethodIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Api\\Controllers\\v1\\Sultans already has a method get().');

        $this->generate(new Endpoint('GET', '/v1/sultans/{id}/again', 'Sultans::get'));
    }

    public function testAMissingControllerIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('api/Controllers/v2/Sultans.php does not exist. Create it with: kipchak make:controller Sultans --api-version=v2');

        $this->generate(new Endpoint('GET', '/v2/sultans', 'Sultans::list'));
    }

    public function testAnImportedNameThatClashesIsWrittenInFull(): void
    {
        $controller = str_replace(
            "use Psr\\Http\\Message\\ServerRequestInterface;\n",
            "use Psr\\Http\\Message\\ServerRequestInterface;\nuse Acme\\HttpNotImplementedException;\n",
            (string) file_get_contents(self::STARTER . 'SultansController.php'),
        );
        $edited = $this->contents($this->generate(new Endpoint('GET', '/v1/sultans/count', 'Sultans::count'), [self::CONTROLLER => $controller]))[self::CONTROLLER];

        self::assertStringContainsString('throw new \\Slim\\Exception\\HttpNotImplementedException($request', $edited);
        self::assertSame(1, substr_count($edited, 'HttpNotImplementedException;'));
    }

    public function testKeepsWindowsLineEndings(): void
    {
        $crlf = static fn (string $file): string => str_replace("\n", "\r\n", (string) file_get_contents(self::STARTER . $file));
        $contents = $this->contents($this->generate(new Endpoint('GET', '/v1/sultans/count', 'Sultans::count'), [
            self::CONTROLLER => $crlf('SultansController.php'),
            self::ROUTES => $crlf('routes.php'),
        ]));

        foreach ($contents as $content) {
            self::assertSame(0, preg_match("/(?<!\r)\n/", $content));
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function invalidEndpoints(): array
    {
        return [
            'method' => ['HEAD', '/v1/sultans', 'Sultans::list', 'is not an HTTP method'],
            'no version' => ['GET', '/sultans', 'Sultans::list', 'does not start with the API version'],
            'no method name' => ['GET', '/v1/sultans', 'Sultans', 'is not Controller::method'],
            'controller name' => ['GET', '/v1/sultans', 'sultans::list', 'is not a controller name'],
            'method name' => ['GET', '/v1/sultans', 'Sultans::List', 'is not a method name'],
        ];
    }

    /**
     * @dataProvider invalidEndpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEndpoints')]
    public function testRefusesAnInvalidEndpoint(string $method, string $path, string $handler, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Endpoint($method, $path, $handler);
    }

    public function testReadsPathParameters(): void
    {
        $endpoint = new Endpoint('GET', '/v1/years/{year:[0-9]{4}}/sultans/{id:\d+}/{slug}', 'Sultans::byYear');

        self::assertSame('/v1/years/{year}/sultans/{id}/{slug}', $endpoint->openApiPath());
        self::assertSame('/years/{year:[0-9]{4}}/sultans/{id:\d+}/{slug}', $endpoint->routePath);
        self::assertSame([
            ['name' => 'year', 'type' => 'string'],
            ['name' => 'id', 'type' => 'integer'],
            ['name' => 'slug', 'type' => 'string'],
        ], $endpoint->parameters());
    }
}
