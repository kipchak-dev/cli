<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Console;

use Kipchak\Cli\Console\AddCommand;
use Kipchak\Cli\Console\ExitCode;
use Kipchak\Cli\Console\NewCommand;
use Kipchak\Cli\Project\Composer;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The add and new commands against a project on disk, with Composer replaced by a fake that records its calls.
 */
final class AddAndNewCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = ProjectFixture::create();
        $fixtures = __DIR__ . '/../fixtures';
        mkdir($this->root . '/drivers');
        copy($fixtures . '/starter/drivers.php', $this->root . '/drivers/drivers.php');

        // installed.json as Composer writes it: each package with its require and extra.
        $packages = [['name' => 'kipchak/core', 'version' => '3.16']];
        foreach (['driver-config', 'driver-logger', 'driver-http', 'driver-anthropic'] as $name) {
            $packages[] = json_decode((string) file_get_contents("{$fixtures}/{$name}.json"), true) + ['version' => '2.17'];
        }
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $packages]));
        mkdir($this->root . '/vendor/kipchak/driver-anthropic', 0777, true);
        file_put_contents($this->root . '/vendor/kipchak/driver-anthropic/sample.config.php', "<?php\n\nuse function Kipchak\\Core\\env;\n\nreturn ['api_key' => env('ANTHROPIC_API_KEY', '')];\n");
    }

    protected function tearDown(): void
    {
        ProjectFixture::remove($this->root);
    }

    private function composer(): Composer
    {
        return new class extends Composer {
            /** @var list<list<string>> */
            public array $calls = [];

            protected function run(array $arguments, ?string $workingDirectory, bool $quiet = false): int
            {
                $this->calls[] = $arguments;

                return 0;
            }
        };
    }

    public function testAddsAnInstalledDriverWithTheDriversItRequires(): void
    {
        $composer = $this->composer();
        $tester = new CommandTester(new AddCommand($composer));

        $code = $tester->execute(['package' => 'driver-anthropic', '--project-dir' => $this->root, '--format' => 'json']);

        self::assertSame(ExitCode::SUCCESS, $code, $tester->getDisplay());
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([['file' => 'config/kipchak.anthropic.php', 'key' => 'ANTHROPIC_API_KEY']], $data['settings']);
        self::assertSame([], $composer->calls, 'An installed package is not required again.');
        self::assertStringEndsWith(
            "Http::initialise(\$container);\nAnthropic::initialise(\$container);\n",
            (string) file_get_contents($this->root . '/drivers/drivers.php'),
        );
        self::assertFileExists($this->root . '/config/kipchak.anthropic.php');
    }

    public function testADryRunWritesNothing(): void
    {
        $before = file_get_contents($this->root . '/drivers/drivers.php');
        $tester = new CommandTester(new AddCommand($this->composer()));

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['package' => 'kipchak/driver-anthropic', '--project-dir' => $this->root, '--dry-run' => true]));
        self::assertStringContainsString('+Anthropic::initialise($container);', $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->root . '/drivers/drivers.php'));
        self::assertFileDoesNotExist($this->root . '/config/kipchak.anthropic.php');
    }

    public function testAnUnknownPackageExitsWithOne(): void
    {
        $composer = new class extends Composer {
            protected function run(array $arguments, ?string $workingDirectory, bool $quiet = false): int
            {
                return 1; // composer show finds nothing
            }
        };
        $tester = new CommandTester(new AddCommand($composer));

        self::assertSame(ExitCode::FAILURE, $tester->execute(['package' => 'nonsense', '--project-dir' => $this->root]));
        self::assertStringContainsString('No Kipchak driver or middleware is called nonsense', $tester->getDisplay());
    }

    public function testNewRunsCreateProject(): void
    {
        $composer = $this->composer();
        $directory = sys_get_temp_dir() . '/kipchak-new-' . bin2hex(random_bytes(4));
        $tester = new CommandTester(new NewCommand($composer));

        self::assertSame(ExitCode::SUCCESS, $tester->execute(['directory' => $directory, '--starter-version' => '4.2']));
        self::assertSame([['create-project', 'kipchak/starter', $directory, '4.2', '--no-interaction']], $composer->calls);
    }

    public function testNewRefusesADirectoryThatIsNotEmpty(): void
    {
        $composer = $this->composer();
        $tester = new CommandTester(new NewCommand($composer));

        self::assertSame(ExitCode::FAILURE, $tester->execute(['directory' => $this->root]));
        self::assertSame([], $composer->calls);
        self::assertStringContainsString('already exists and is not empty', $tester->getDisplay());
    }
}
