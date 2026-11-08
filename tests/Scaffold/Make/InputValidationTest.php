<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Scaffold\Make;

use Kipchak\Cli\Scaffold\Make\Field;
use Kipchak\Cli\Scaffold\Make\MakeGenerators;
use Kipchak\Cli\Scaffold\Make\Resource;
use Kipchak\Cli\Scaffold\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * New code states rules with Dto\Field and reads bodies with Dto\Input when the project's core has them (3.19). A project
 * on an older core gets the form that compiles there.
 */
final class InputValidationTest extends TestCase
{
    /**
     * @return array<string, string> path => content
     */
    private function resource(string $coreVersion): array
    {
        $changeSet = new MakeGenerators()->resource(
            new Project(['Api\\' => 'api/'], $coreVersion),
            new Resource('Orders', crud: true),
            Field::parseList('id:int,reference:string,note:?string'),
        );
        $files = [];
        foreach ($changeSet->changes as $change) {
            $files[$change->path] = $change->content;
        }

        return $files;
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function versions(): array
    {
        return [
            'the first with it' => ['3.19', true],
            'a tag with a v' => ['v3.19.1', true],
            'a later minor' => ['3.20', true],
            'a development version' => ['dev-master', true],
            'the one before' => ['3.18', false],
            'a patch of an older minor' => ['3.17.1', false],
        ];
    }

    #[DataProvider('versions')]
    public function testTheFormFollowsTheProjectsCore(string $version, bool $expected): void
    {
        self::assertSame($expected, new Project([], $version)->hasInputValidation());
    }

    public function testNewCoreGetsFieldAndInput(): void
    {
        $files = $this->resource('3.19');
        $dto = $files['api/DataTransferObjects/v1/Order.php'];
        $controller = $files['api/Controllers/v1/Orders.php'];
        $model = $files['api/Models/Orders.php'];

        self::assertStringContainsString("use Kipchak\\Core\\Components\\Dto\\Field;\n", $dto);
        self::assertStringContainsString('#[Field(description:', $dto);
        self::assertStringContainsString("#[OA\\Property(description: 'Identifier", $dto);

        self::assertStringContainsString("use Api\\DataTransferObjects\\v1\\Order;\n", $controller);
        self::assertStringContainsString("use Kipchak\\Core\\Components\\Dto\\Input;\n", $controller);
        self::assertStringContainsString("Input::from(\$request, Order::class, ['id' => 0])", $controller);
        self::assertStringNotContainsString('MappingError', $controller);
        self::assertStringNotContainsString('private function problems', $controller);
        self::assertStringNotContainsString('HttpBadRequestException', $controller);

        self::assertStringContainsString('public function create(Order $input): Order', $model);
        self::assertStringNotContainsString('MappingError', $model);
    }

    public function testControlOlderCoreKeepsTheFormThatCompilesThere(): void
    {
        $files = $this->resource('3.18');
        $dto = $files['api/DataTransferObjects/v1/Order.php'];
        $controller = $files['api/Controllers/v1/Orders.php'];

        self::assertStringNotContainsString('Dto\\Field', $dto);
        self::assertStringNotContainsString('#[Field(', $dto);
        self::assertStringNotContainsString('Dto\\Input', $controller);
        self::assertStringContainsString('private function problems(MappingError $error)', $controller);
        self::assertStringContainsString('public function create(array $input)', $files['api/Models/Orders.php']);
    }

    public function testEveryGeneratedFileIsValidPhp(): void
    {
        foreach (['3.19', '3.18'] as $version) {
            foreach ($this->resource($version) as $path => $content) {
                $file = tempnam(sys_get_temp_dir(), 'lint');
                file_put_contents($file, $content);
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
                unlink($file);
                self::assertSame(0, $code, "{$version} {$path}: " . implode("\n", $output));
            }
        }
    }
}
