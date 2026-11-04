<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Project;

use Kipchak\Cli\Project\ProjectException;
use Kipchak\Cli\Project\ProjectReader;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\TestCase;

final class ProjectReaderTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        array_map(ProjectFixture::remove(...), $this->roots);
    }

    private function fixture(string $coreVersion = '3.16', bool $installed = true): string
    {
        return $this->roots[] = ProjectFixture::create($coreVersion, $installed);
    }

    public function testReadsNamespacesVersionAndRequestedFiles(): void
    {
        $root = $this->fixture();
        mkdir($root . '/routes/v1', 0777, true);
        file_put_contents($root . '/routes/v1/orders.php', '<?php // routes');

        $project = new ProjectReader()->read($root, ['routes/v1/orders.php', 'routes/v1/missing.php']);

        self::assertSame(['Api\\' => 'api/'], $project->namespaces);
        self::assertSame('3.16', $project->coreVersion);
        self::assertSame('<?php // routes', $project->file('routes/v1/orders.php'));
        self::assertFalse($project->hasFile('routes/v1/missing.php'));
    }

    public function testFindsTheRootFromASubdirectory(): void
    {
        $root = $this->fixture();
        mkdir($root . '/api/Controllers', 0777, true);

        self::assertSame(realpath($root), new ProjectReader()->findRoot($root . '/api/Controllers'));
    }

    public function testRefusesADirectoryOutsideAProject(): void
    {
        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('This is not a Kipchak project');

        new ProjectReader()->findRoot(sys_get_temp_dir());
    }

    public function testRefusesAnUnsupportedCoreVersion(): void
    {
        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('kipchak/core 2.9; this release of kipchak/cli supports kipchak/core ^3');

        new ProjectReader()->read($this->fixture('2.9'));
    }

    public function testAsksForComposerInstallWhenDependenciesAreMissing(): void
    {
        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('composer install');

        new ProjectReader()->read($this->fixture(installed: false));
    }
}
