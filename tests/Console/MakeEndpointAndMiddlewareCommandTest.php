<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Console;

use Kipchak\Cli\Console\Application;
use Kipchak\Cli\Console\ExitCode;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MakeEndpointAndMiddlewareCommandTest extends TestCase
{
    private const string STARTER = __DIR__ . '/../fixtures/starter/';

    private string $root;

    protected function setUp(): void
    {
        $this->root = ProjectFixture::create();
        mkdir($this->root . '/api/Controllers/v1', 0777, true);
        mkdir($this->root . '/routes/v1', 0777, true);
        mkdir($this->root . '/middlewares');
        copy(self::STARTER . 'sultans/SultansController.php', $this->root . '/api/Controllers/v1/Sultans.php');
        copy(self::STARTER . 'sultans/routes.php', $this->root . '/routes/v1/sultans.php');
        copy(self::STARTER . 'middlewares.php', $this->root . '/middlewares/middlewares.php');
    }

    protected function tearDown(): void
    {
        ProjectFixture::remove($this->root);
    }

    private function tester(string $command): CommandTester
    {
        return new CommandTester(new Application()->find($command));
    }

    public function testMakeEndpointEditsTheControllerAndTheRouteFileOnDisk(): void
    {
        $tester = $this->tester('make:endpoint');
        $code = $tester->execute(['method' => 'GET', 'path' => '/v1/sultans/count', 'handler' => 'Sultans::count', '--project-dir' => $this->root]);

        self::assertSame(ExitCode::SUCCESS, $code, $tester->getDisplay());
        self::assertStringContainsString('public function count(', (string) file_get_contents($this->root . '/api/Controllers/v1/Sultans.php'));
        self::assertStringContainsString("\$group->get('/sultans/count', [Controllers\\v1\\Sultans::class, 'count']);", (string) file_get_contents($this->root . '/routes/v1/sultans.php'));
    }

    public function testMakeEndpointRefusesAMissingController(): void
    {
        $tester = $this->tester('make:endpoint');
        $code = $tester->execute(['method' => 'GET', 'path' => '/v1/orders', 'handler' => 'Orders::list', '--project-dir' => $this->root, '--format' => 'json']);

        self::assertSame(ExitCode::FAILURE, $code);
        self::assertStringContainsString('kipchak make:controller Orders', json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['error']);
        self::assertFileDoesNotExist($this->root . '/routes/v1/orders.php');
    }

    public function testMakeMiddlewareGlobalWritesBothFiles(): void
    {
        $tester = $this->tester('make:middleware');
        $code = $tester->execute(['name' => 'RequireRole', '--global' => true, '--project-dir' => $this->root]);

        self::assertSame(ExitCode::SUCCESS, $code, $tester->getDisplay());
        self::assertFileExists($this->root . '/api/Middlewares/RequireRole.php');
        self::assertStringContainsString('$app->add(new RequireRole());', (string) file_get_contents($this->root . '/middlewares/middlewares.php'));
    }
}
