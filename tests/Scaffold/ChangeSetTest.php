<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Scaffold;

use InvalidArgumentException;
use Kipchak\Cli\Scaffold\ChangeSet;
use Kipchak\Cli\Scaffold\FileChange;
use Kipchak\Cli\Scaffold\Project;
use PHPUnit\Framework\TestCase;

final class ChangeSetTest extends TestCase
{
    public function testSerialisesChangesWithDiffs(): void
    {
        $set = new ChangeSet(
            [
                FileChange::create('api/Models/Orders.php', "<?php\n"),
                FileChange::modify('routes/v1/orders.php', "a\n", "a\nb\n"),
            ],
            ['Add the route by hand.'],
        );

        $data = $set->toArray();

        self::assertSame(['path', 'action', 'content', 'diff'], array_keys($data['changes'][0]));
        self::assertSame('create', $data['changes'][0]['action']);
        self::assertStringContainsString("--- /dev/null\n+++ b/api/Models/Orders.php", $data['changes'][0]['diff']);
        self::assertSame('modify', $data['changes'][1]['action']);
        self::assertStringContainsString("+b\n", $data['changes'][1]['diff']);
        self::assertSame(['Add the route by hand.'], $data['messages']);
    }

    public function testRefusesTwoChangesToOneFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChangeSet([FileChange::create('a.php', ''), FileChange::create('a.php', '')]);
    }

    public function testRefusesPathsOutsideTheProject(): void
    {
        foreach (['/etc/passwd', '../outside.php', 'api/../../outside.php', ''] as $path) {
            try {
                FileChange::create($path, '');
                self::fail("'{$path}' was accepted.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testResolvesNamespacesFromThePsr4Map(): void
    {
        $project = new Project(['Api\\' => 'api/', 'Tests\\' => 'tests/'], '3.16');

        self::assertSame('Api\\Controllers\\v1', $project->namespaceFor('api/Controllers/v1'));
        self::assertSame('Api', $project->namespaceFor('api'));
        self::assertSame('Tests', $project->namespaceFor('tests/'));
        self::assertNull($project->namespaceFor('routes/v1'));
        self::assertSame(3, $project->coreMajor());
    }
}
