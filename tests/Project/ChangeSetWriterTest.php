<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Project;

use Kipchak\Cli\Project\ChangeSetWriter;
use Kipchak\Cli\Project\ConflictException;
use Kipchak\Cli\Scaffold\ChangeSet;
use Kipchak\Cli\Scaffold\FileChange;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\TestCase;

final class ChangeSetWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = ProjectFixture::create();
        mkdir($this->root . '/routes', 0777, true);
        file_put_contents($this->root . '/routes/app.php', "original\n");
    }

    protected function tearDown(): void
    {
        ProjectFixture::remove($this->root);
    }

    public function testCreatesAndModifiesFiles(): void
    {
        new ChangeSetWriter()->apply(new ChangeSet([
            FileChange::create('api/Models/Orders.php', "<?php\n"),
            FileChange::modify('routes/app.php', "original\n", "changed\n"),
        ]), $this->root);

        self::assertSame("<?php\n", file_get_contents($this->root . '/api/Models/Orders.php'));
        self::assertSame("changed\n", file_get_contents($this->root . '/routes/app.php'));
        self::assertSame([], glob($this->root . '/**/*.kipchak-*') ?: []);
    }

    public function testWritesNothingWhenAFileToCreateExists(): void
    {
        try {
            new ChangeSetWriter()->apply(new ChangeSet([
                FileChange::create('api/Models/Orders.php', "<?php\n"),
                FileChange::create('routes/app.php', "new\n"),
            ]), $this->root);
            self::fail('The change set was applied.');
        } catch (ConflictException $exception) {
            self::assertSame(['routes/app.php already exists.'], $exception->conflicts);
        }

        self::assertFileDoesNotExist($this->root . '/api/Models/Orders.php');
        self::assertSame("original\n", file_get_contents($this->root . '/routes/app.php'));
    }

    public function testWritesNothingWhenAFileChangedSinceItWasRead(): void
    {
        file_put_contents($this->root . '/routes/app.php', "edited meanwhile\n");

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('routes/app.php has changed since it was read.');

        new ChangeSetWriter()->apply(new ChangeSet([FileChange::modify('routes/app.php', "original\n", "changed\n")]), $this->root);
    }
}
