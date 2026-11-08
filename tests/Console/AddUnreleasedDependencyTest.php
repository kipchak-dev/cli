<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Console;

use Kipchak\Cli\Console\AddCommand;
use Kipchak\Cli\Console\ExitCode;
use Kipchak\Cli\Project\Composer;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `kipchak add doctrine` in a project that accepts only stable packages: doctrine/orm 4 exists only as the 4.0.x-dev
 * branch, so the driver is required together with "doctrine/orm:^4@dev". Composer is a fake that records its calls
 * and installs the packages as Composer would, into vendor/composer/installed.json.
 */
final class AddUnreleasedDependencyTest extends TestCase
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

    /**
     * @param list<string> $fixtures
     */
    private function install(array $fixtures): void
    {
        $packages = [['name' => 'kipchak/core', 'version' => '3.17.1']];
        foreach ($fixtures as $fixture) {
            $packages[] = json_decode((string) file_get_contents(self::FIXTURES . "/{$fixture}.json"), true) + ['version' => '1.54'];
        }
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $packages]));
    }

    /**
     * @param array<string, mixed> $settings merged into the project's composer.json
     */
    private function composerJson(array $settings): void
    {
        $file = $this->root . '/composer.json';
        file_put_contents($file, json_encode(array_replace_recursive(json_decode((string) file_get_contents($file), true), $settings)));
    }

    private function composer(): Composer
    {
        $test = $this;

        return new class ($test) extends Composer {
            /** @var list<list<string>> */
            public array $calls = [];

            public function __construct(private readonly AddUnreleasedDependencyTest $test)
            {
            }

            protected function run(array $arguments, ?string $workingDirectory, bool $quiet = false): int
            {
                if ($arguments[0] === 'show') {
                    // Only the drivers exist; there is no middleware-doctrine or middleware-anthropic.
                    return in_array(end($arguments), ['kipchak/driver-doctrine', 'kipchak/driver-anthropic'], true) ? 0 : 1;
                }
                $this->calls[] = $arguments;
                if ($arguments[0] === 'require') {
                    $this->test->installed($arguments[1]);
                }

                return 0;
            }
        };
    }

    /**
     * What the fake Composer installs for a package it is asked to require.
     */
    public function installed(string $package): void
    {
        $this->install(match ($package) {
            'kipchak/driver-doctrine' => ['driver-config', 'driver-logger', 'driver-filecache', 'driver-memcached', 'driver-doctrine'],
            default => ['driver-config', 'driver-logger', 'driver-http', 'driver-anthropic'],
        });
    }

    /**
     * @return array{0: list<list<string>>, 1: array<string, mixed>} Composer's calls and the command's JSON output
     */
    private function add(string $package): array
    {
        $composer = $this->composer();
        $tester = new CommandTester(new AddCommand($composer));
        $code = $tester->execute(['package' => $package, '--project-dir' => $this->root, '--format' => 'json']);
        self::assertSame(ExitCode::SUCCESS, $code, $tester->getDisplay());

        return [$composer->calls, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function testTheDriverIsRequiredWithTheUnreleasedOrmAllowed(): void
    {
        [$calls, $output] = $this->add('doctrine');

        self::assertContains(['require', 'kipchak/driver-doctrine', 'doctrine/orm:^4@dev'], $calls);
        self::assertContains(
            'doctrine/orm 4 is not yet a stable release, so composer.json now requires "doctrine/orm": "^4@dev": only that package may be a development version.',
            $output['messages'],
        );
    }

    public function testAProjectThatRequiresTheOrmKeepsItsOwnConstraint(): void
    {
        $this->composerJson(['require' => ['doctrine/orm' => '4.0.x-dev#d1003fc']]);

        [$calls, $output] = $this->add('doctrine');

        self::assertContains(['require', 'kipchak/driver-doctrine'], $calls);
        self::assertSame([], array_values(array_filter($output['messages'], static fn (string $m): bool => str_contains($m, 'doctrine/orm'))));
    }

    public function testAProjectThatAcceptsDevelopmentVersionsNeedsNoFlag(): void
    {
        $this->composerJson(['minimum-stability' => 'dev']);

        [$calls] = $this->add('doctrine');

        self::assertContains(['require', 'kipchak/driver-doctrine'], $calls);
    }

    public function testControlAPackageWithoutAnUnreleasedDependencyIsRequiredAlone(): void
    {
        [$calls, $output] = $this->add('driver-anthropic');

        self::assertContains(['require', 'kipchak/driver-anthropic'], $calls);
        self::assertSame([], array_values(array_filter($output['messages'], static fn (string $m): bool => str_contains($m, 'stable release'))));
    }
}
