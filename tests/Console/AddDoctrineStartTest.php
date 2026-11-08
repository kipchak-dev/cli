<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Console;

use Kipchak\Cli\Console\AddCommand;
use Kipchak\Cli\Console\ExitCode;
use Kipchak\Cli\Project\Composer;
use Kipchak\Cli\Tests\ProjectFixture;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `kipchak add doctrine` leaves a project whose first migration works: SQLite, not the driver's disabled MySQL sample,
 * and no Memcached unless asked for. Composer is a fake that installs the packages as Composer would.
 */
final class AddDoctrineStartTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures';

    private string $root;

    protected function setUp(): void
    {
        $this->root = ProjectFixture::create();
        mkdir($this->root . '/drivers');
        copy(self::FIXTURES . '/starter/drivers.php', $this->root . '/drivers/drivers.php');
        $this->install(['driver-config', 'driver-logger']);
    }

    protected function tearDown(): void
    {
        ProjectFixture::remove($this->root);
    }

    public function testMemcachedIsNotInitialisedAndItsConfigurationIsNotWritten(): void
    {
        $this->add('doctrine');

        $drivers = (string) file_get_contents($this->root . '/drivers/drivers.php');
        self::assertStringContainsString('Doctrine::initialise(', $drivers);
        self::assertStringContainsString('Filecache::initialise(', $drivers);
        self::assertStringNotContainsString('Memcached', $drivers);
        self::assertFileDoesNotExist($this->root . '/config/kipchak.memcached.php');
    }

    public function testControlMemcachedIsAddedWhenAskedFor(): void
    {
        $this->add('doctrine');
        $this->add('memcached');

        self::assertStringContainsString('Memcached::initialise(', (string) file_get_contents($this->root . '/drivers/drivers.php'));
    }

    public function testControlAnotherPackageStillGetsTheDriversItRequires(): void
    {
        $this->add('anthropic');

        self::assertStringContainsString('Http::initialise(', (string) file_get_contents($this->root . '/drivers/drivers.php'));
    }

    public function testTheWrittenConfigurationOpensASqliteDatabaseWithNoServer(): void
    {
        $this->add('doctrine');

        $config = $this->writtenConfig();
        $connection = $config['dbal']['connections']['primary'];
        self::assertTrue($config['dbal']['enabled']);
        self::assertTrue($config['orm']['enabled']);
        self::assertSame('pdo_sqlite', $connection['driver']);
        self::assertSame('file', $config['orm']['entity_managers']['primary']['cache']['store']);
        self::assertDirectoryExists($config['orm']['entity_managers']['primary']['metadata_dirs'][0]);

        $database = new PDO('sqlite:' . $connection['path']);
        $database->exec('CREATE TABLE books (id INTEGER PRIMARY KEY)');
        self::assertFileExists($connection['path']);
    }

    public function testConfigurationAndOutputSaySqliteIsOnlyTheStartingDefault(): void
    {
        $output = $this->add('doctrine');

        self::assertStringContainsString('starting default', (string) file_get_contents($this->root . '/config/kipchak.doctrine.php'));
        self::assertStringContainsString('MySQL or', (string) file_get_contents($this->root . '/config/kipchak.doctrine.php'));
        $messages = implode("\n", $output['messages']);
        self::assertStringContainsString('SQLite is the starting default', $messages);
        self::assertStringContainsString('MySQL or PostgreSQL in production', $messages);
        self::assertStringContainsString('migrations:up', $messages);
    }

    public function testTheDatabaseFileIsIgnoredOnce(): void
    {
        file_put_contents($this->root . '/.gitignore', "vendor/\n");

        $this->add('doctrine');
        self::assertSame("vendor/\n/var/*.sqlite\n", file_get_contents($this->root . '/.gitignore'));

        unlink($this->root . '/config/kipchak.doctrine.php');
        $this->restoreDrivers();
        $this->add('doctrine');
        self::assertSame("vendor/\n/var/*.sqlite\n", file_get_contents($this->root . '/.gitignore'));
    }

    public function testAnExistingConfigurationIsKeptAndNothingElseIsAssumed(): void
    {
        mkdir($this->root . '/config');
        file_put_contents($this->root . '/config/kipchak.doctrine.php', "<?php return ['mine' => true];\n");

        $output = $this->add('doctrine');

        self::assertSame("<?php return ['mine' => true];\n", file_get_contents($this->root . '/config/kipchak.doctrine.php'));
        self::assertDirectoryDoesNotExist($this->root . '/var');
        self::assertFileDoesNotExist($this->root . '/.gitignore');
        self::assertStringNotContainsString('SQLite', implode("\n", $output['messages']));
    }

    public function testTheScaffoldedProblemsNameTheField(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/controller-crud-methods.php.tpl');

        self::assertStringContainsString('{$message->path()}: {$message}', $template);
    }

    public function installed(string $package): void
    {
        $this->install(match ($package) {
            'kipchak/driver-doctrine' => ['driver-config', 'driver-logger', 'driver-filecache', 'driver-memcached', 'driver-doctrine'],
            'kipchak/driver-memcached' => ['driver-config', 'driver-logger', 'driver-filecache', 'driver-memcached', 'driver-doctrine'],
            default => ['driver-config', 'driver-logger', 'driver-http', 'driver-anthropic'],
        });
    }

    /**
     * @param list<string> $fixtures
     */
    private function install(array $fixtures): void
    {
        $packages = [['name' => 'kipchak/core', 'version' => '3.18']];
        foreach ($fixtures as $fixture) {
            $packages[] = json_decode((string) file_get_contents(self::FIXTURES . "/{$fixture}.json"), true) + ['version' => '1.54'];
        }
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $packages]));
    }

    private function restoreDrivers(): void
    {
        copy(self::FIXTURES . '/starter/drivers.php', $this->root . '/drivers/drivers.php');
    }

    /**
     * The written configuration, loaded in its own process with only the env() function it needs.
     *
     * @return array<string, mixed>
     */
    private function writtenConfig(): array
    {
        $code = 'namespace Kipchak\Core { function env($name, $default = null) { return $default; } } '
            . 'namespace { echo json_encode(require $argv[1]); }';
        $output = shell_exec(implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-r', $code, $this->root . '/config/kipchak.doctrine.php'])));

        return json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed> the command's JSON output
     */
    private function add(string $package): array
    {
        $test = $this;
        $composer = new class ($test) extends Composer {
            public function __construct(private readonly AddDoctrineStartTest $test)
            {
            }

            protected function run(array $arguments, ?string $workingDirectory, bool $quiet = false): int
            {
                if ($arguments[0] === 'show') {
                    return in_array(end($arguments), ['kipchak/driver-doctrine', 'kipchak/driver-memcached', 'kipchak/driver-anthropic'], true) ? 0 : 1;
                }
                if ($arguments[0] === 'require') {
                    $this->test->installed($arguments[1]);
                }

                return 0;
            }
        };
        $tester = new CommandTester(new AddCommand($composer));
        $code = $tester->execute(['package' => $package, '--project-dir' => $this->root, '--format' => 'json']);
        self::assertSame(ExitCode::SUCCESS, $code, $tester->getDisplay());

        return json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    }
}
