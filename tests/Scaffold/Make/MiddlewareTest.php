<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Scaffold\Make;

use Kipchak\Cli\Scaffold\ChangeAction;
use Kipchak\Cli\Scaffold\Make\MakeGenerators;
use Kipchak\Cli\Scaffold\Project;
use Kipchak\Cli\Scaffold\SourceFile;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    private const string MIDDLEWARES = 'middlewares/middlewares.php';

    private function starterMiddlewares(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../fixtures/starter/middlewares.php');
    }

    private function project(?string $middlewares): Project
    {
        return new Project(['Api\\' => 'api/'], '3.16', $middlewares === null ? [] : [self::MIDDLEWARES => $middlewares]);
    }

    public function testWritesAPsr15Middleware(): void
    {
        $changeSet = new MakeGenerators()->middleware($this->project($this->starterMiddlewares()), 'RequireRole', false);

        self::assertCount(1, $changeSet->changes);
        $file = $changeSet->changes[0];
        self::assertSame('api/Middlewares/RequireRole.php', $file->path);
        self::assertSame(ChangeAction::Create, $file->action);
        self::assertStringContainsString("namespace Api\\Middlewares;\n", $file->content);
        self::assertStringContainsString('final readonly class RequireRole implements MiddlewareInterface', $file->content);
        self::assertStringContainsString('->add(new RequireRole())', $file->content);
        new SourceFile($file->content);
    }

    public function testGlobalAddsItBetweenTheTwoErrorLines(): void
    {
        $original = $this->starterMiddlewares();
        $changeSet = new MakeGenerators()->middleware($this->project($original), 'RequireRole', true);
        $edited = $changeSet->changes[1]->content;

        self::assertSame(self::MIDDLEWARES, $changeSet->changes[1]->path);
        self::assertStringContainsString("use Api\\Middlewares\\RequireRole;\nuse Kipchak\\Middleware\\Error\\Error;\n", $edited);
        self::assertStringContainsString("// Other middleware goes here.\n\$app->add(new RequireRole());\n\nError::initialise(\$app);\n", $edited);
        // Nothing else changes.
        self::assertSame($original, str_replace(["use Api\\Middlewares\\RequireRole;\n", "\$app->add(new RequireRole());\n"], '', $edited));
        self::assertSame([], $changeSet->messages);
    }

    public function testAMiddlewareAlreadyAddedIsNotAddedTwice(): void
    {
        $once = new MakeGenerators()->middleware($this->project($this->starterMiddlewares()), 'RequireRole', true)->changes[1]->content;
        $changeSet = new MakeGenerators()->middleware($this->project($once), 'RequireRole', true);

        self::assertCount(1, $changeSet->changes);
        self::assertSame(['middlewares/middlewares.php already adds Api\\Middlewares\\RequireRole.'], $changeSet->messages);
    }

    public function testWithOneErrorLineItGoesLastAndSaysSo(): void
    {
        $single = "<?php\n\nuse Kipchak\\Middleware\\Error\\Error;\n\nError::initialise(\$app);\n";
        $changeSet = new MakeGenerators()->middleware($this->project($single), 'RequireRole', true);

        self::assertStringEndsWith("Error::initialise(\$app);\n\$app->add(new RequireRole());\n", $changeSet->changes[1]->content);
        self::assertStringContainsString('initialises the Error middleware once', $changeSet->messages[0]);
    }

    public function testWithoutTheFileItPrintsTheLine(): void
    {
        $changeSet = new MakeGenerators()->middleware($this->project(null), 'RequireRole', true);

        self::assertCount(1, $changeSet->changes);
        self::assertSame(['middlewares/middlewares.php was not found. Add this line to it: $app->add(new \\Api\\Middlewares\\RequireRole());'], $changeSet->messages);
    }
}
