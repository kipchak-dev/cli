<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Scaffold\Make;

use Kipchak\Cli\Scaffold\Make\Field;
use Kipchak\Cli\Scaffold\Make\MakeGenerators;
use Kipchak\Cli\Scaffold\Make\Resource;
use Kipchak\Cli\Scaffold\Project;
use PHPUnit\Framework\TestCase;

/**
 * Generated code is the code a developer would write following the starter (SR-2026-012, objective 2): generating
 * the starter's Sultans resource reproduces the starter's files, apart from the example data. The starter's files are
 * copied into tests/fixtures/starter/sultans from kipchak/starter.
 */
final class StarterComparisonTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/starter/sultans/';

    /**
     * @return array<string, array{0: string, 1: string}> generated path => starter fixture
     */
    public static function files(): array
    {
        return [
            'DTO' => ['api/DataTransferObjects/v1/MamlukSultan.php', 'MamlukSultan.php'],
            'model' => ['api/Models/Sultans.php', 'Sultans.php'],
            'controller' => ['api/Controllers/v1/Sultans.php', 'SultansController.php'],
            'routes' => ['routes/v1/sultans.php', 'routes.php'],
            'test' => ['tests/SultansTest.php', 'SultansTest.php'],
        ];
    }

    /**
     * @dataProvider files
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('files')]
    public function testGeneratingSultansReproducesTheStarter(string $generatedPath, string $fixture): void
    {
        $changeSet = new MakeGenerators()->resource(
            new Project(['Api\\' => 'api/'], '3.16'),
            new Resource('Sultans', dto: 'MamlukSultan'),
            Field::parseList('id:int,name:string,reignFrom:int,reignTo:int'),
        );
        $generated = [];
        foreach ($changeSet->changes as $change) {
            $generated[$change->path] = $change->content;
        }

        $starter = (string) file_get_contents(self::FIXTURES . $fixture);
        if ($fixture === 'SultansTest.php') {
            // The starter's test also checks /status, which is not part of the resource.
            $starter = (string) preg_replace('/\n    public function testTheStatusCheckAnswers\(\).*?\n    }\n/s', '', $starter);
        }

        self::assertSame(self::normalise($starter), self::normalise($generated[$generatedPath]));
    }

    /**
     * Removes what is example data rather than code: string and number literals, and the model's example rows.
     */
    public static function normalise(string $source): string
    {
        $source = (string) preg_replace('/(private const array \w+ = \[\n).*?(\n    \];)/s', '$1        ROWS$2', $source);
        $source = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'S'", $source);
        $source = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '"S"', $source);

        return (string) preg_replace('/(?<![\w$])\d+(\.\d+)?/', 'N', $source);
    }
}
