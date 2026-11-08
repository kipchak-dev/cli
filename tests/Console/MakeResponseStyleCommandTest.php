<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Console;

use Kipchak\Cli\Console\Application;
use Kipchak\Cli\Console\ExitCode;
use Kipchak\Cli\Console\MakeEndpointCommand;
use Kipchak\Cli\Console\MakeResourceCommand;
use Kipchak\Cli\Project\Inspector;
use Kipchak\Cli\Project\ProjectException;
use Kipchak\Cli\Scaffold\Validate\Inspection;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * make:resource and make:endpoint ask the project's generator how to write responses, and write the long form
 * whenever the answer is not certain.
 */
final class MakeResponseStyleCommandTest extends TestCase
{
    private const string LONG_FORM = "new OA\\Property(property: 'code', type: 'integer', example: 200)";
    private const string NOT_FOUND_REFERENCE = "ref: '#/components/responses/NotFound'";

    private string $root;

    protected function setUp(): void
    {
        $this->root = ProjectFixture::create();
        mkdir($this->root . '/api/Controllers/v1', 0777, true);
        mkdir($this->root . '/routes/v1', 0777, true);
        copy(__DIR__ . '/../fixtures/starter/sultans/SultansController.php', $this->root . '/api/Controllers/v1/Sultans.php');
        copy(__DIR__ . '/../fixtures/starter/sultans/routes.php', $this->root . '/routes/v1/sultans.php');
    }

    protected function tearDown(): void
    {
        ProjectFixture::remove($this->root);
    }

    private static function inspector(?Inspection $inspection): Inspector
    {
        return new class ($inspection) extends Inspector {
            public function __construct(private readonly ?Inspection $inspection)
            {
            }

            public function inspect(string $root, bool $document = true, bool $routes = true): Inspection
            {
                return $this->inspection ?? throw new ProjectException('The project could not be inspected.');
            }
        };
    }

    private static function starter44(): Inspection
    {
        return Inspection::fromJson((string) file_get_contents(__DIR__ . '/../fixtures/validate/starter-4.4-inspection.json'));
    }

    private function make(Command $command, array $arguments): string
    {
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($application->find((string) $command->getName()));
        $code = $tester->execute($arguments + ['--project-dir' => $this->root]);
        self::assertSame(ExitCode::SUCCESS, $code, $tester->getDisplay());

        return (string) file_get_contents($this->root . '/api/Controllers/v1/' . ($command instanceof MakeResourceCommand ? 'Caliphs.php' : 'Sultans.php'));
    }

    public function testMakeResourceWritesTheShortFormWhenTheGeneratorRegistersEnvelope(): void
    {
        $controller = $this->make(new MakeResourceCommand(self::inspector(self::starter44())), ['name' => 'Caliphs', '--fields' => 'id:int,name:string']);

        self::assertStringContainsString("content: new OA\\JsonContent(ref: '#/components/schemas/Caliph'),", $controller);
        self::assertStringContainsString("new OA\\Response(response: 404, description: 'No caliph has that ID.'),", $controller);
        self::assertStringNotContainsString(self::LONG_FORM, $controller);
    }

    public function testMakeResourceWritesTheLongFormWhenTheProjectCannotBeInspected(): void
    {
        $controller = $this->make(new MakeResourceCommand(self::inspector(null)), ['name' => 'Caliphs', '--fields' => 'id:int,name:string']);

        self::assertStringContainsString(self::LONG_FORM, $controller);
        self::assertStringContainsString(self::NOT_FOUND_REFERENCE, $controller);
    }

    public function testWithTheRealInspectorAProjectWithoutDependenciesGetsTheLongForm(): void
    {
        // ProjectFixture has no vendor/autoload.php, so the inspection script fails.
        $controller = $this->make(new MakeResourceCommand(), ['name' => 'Caliphs', '--fields' => 'id:int,name:string']);

        self::assertStringContainsString(self::LONG_FORM, $controller);
    }

    public function testMakeEndpointFollowsTheSameStyle(): void
    {
        $arguments = ['method' => 'GET', 'path' => '/v1/sultans/{id:[0-9]+}/successor', 'handler' => 'Sultans::successor', '--dto' => 'MamlukSultan'];
        $short = $this->make(new MakeEndpointCommand(self::inspector(self::starter44())), $arguments);
        self::assertStringContainsString("content: new OA\\JsonContent(ref: '#/components/schemas/MamlukSultan'),\n            ),\n            new OA\\Response(response: 404, description: 'Not found.'),", $short);

        copy(__DIR__ . '/../fixtures/starter/sultans/SultansController.php', $this->root . '/api/Controllers/v1/Sultans.php');
        copy(__DIR__ . '/../fixtures/starter/sultans/routes.php', $this->root . '/routes/v1/sultans.php');
        $long = $this->make(new MakeEndpointCommand(self::inspector(null)), $arguments);
        self::assertStringContainsString(self::NOT_FOUND_REFERENCE, $long);
        self::assertStringContainsString("new OA\\Property(property: 'data', ref: '#/components/schemas/MamlukSultan'),", $long);
    }
}
