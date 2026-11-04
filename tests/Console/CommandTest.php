<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Console;

use Kipchak\Cli\Console\Application;
use Kipchak\Cli\Console\Command;
use Kipchak\Cli\Console\ExitCode;
use Kipchak\Cli\Scaffold\ChangeSet;
use Kipchak\Cli\Scaffold\FileChange;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = ProjectFixture::create();
    }

    protected function tearDown(): void
    {
        ProjectFixture::remove($this->root);
    }

    /**
     * A command that writes one file, standing in for the make: commands.
     */
    private function writer(): CommandTester
    {
        $command = new class ('test:write') extends Command {
            protected function configure(): void
            {
                parent::configure();
                $this->addDryRunOption();
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return $this->guard($input, $output, fn (): int => $this->applyChangeSet(
                    $input,
                    $output,
                    new ChangeSet([FileChange::create('api/Models/Orders.php', "<?php\n")], ['A message.']),
                ));
            }
        };
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('test:write'));
    }

    public function testADryRunShowsTheDiffAndWritesNothing(): void
    {
        $tester = $this->writer();

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--project-dir' => $this->root, '--dry-run' => true]));
        self::assertStringContainsString('+++ b/api/Models/Orders.php', $tester->getDisplay());
        self::assertStringContainsString('Dry run: nothing was written.', $tester->getDisplay());
        self::assertFileDoesNotExist($this->root . '/api/Models/Orders.php');
    }

    public function testWritesAndReportsAsJson(): void
    {
        $tester = $this->writer();

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--project-dir' => $this->root, '--format' => 'json']));
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['written']);
        self::assertSame('api/Models/Orders.php', $data['changes'][0]['path']);
        self::assertSame(['A message.'], $data['messages']);
        self::assertFileExists($this->root . '/api/Models/Orders.php');
    }

    public function testAConflictExitsWithOneAndNamesTheFile(): void
    {
        mkdir($this->root . '/api/Models', 0777, true);
        file_put_contents($this->root . '/api/Models/Orders.php', 'mine');
        $tester = $this->writer();

        self::assertSame(ExitCode::FAILURE, $tester->execute(['--project-dir' => $this->root, '--format' => 'json']));
        self::assertSame(['api/Models/Orders.php already exists.'], json_decode($tester->getDisplay(), true)['conflicts']);
        self::assertSame('mine', file_get_contents($this->root . '/api/Models/Orders.php'));
    }

    public function testOutsideAProjectExitsWithTwo(): void
    {
        $tester = new CommandTester(new Application()->find('project'));

        self::assertSame(ExitCode::CANNOT_RUN, $tester->execute(['--project-dir' => sys_get_temp_dir()]));
        self::assertStringContainsString('This is not a Kipchak project', $tester->getDisplay());
    }

    public function testTheProjectCommandReportsTheProject(): void
    {
        $tester = new CommandTester(new Application()->find('project'));

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['--project-dir' => $this->root, '--format' => 'json']));
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('3.16', $data['coreVersion']);
        self::assertSame(['Api\\' => 'api/'], $data['namespaces']);
    }
}
