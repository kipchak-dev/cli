<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Scaffold\Add;

use Kipchak\Cli\Scaffold\Add\AddPackages;
use Kipchak\Cli\Scaffold\Add\DependencyResolver;
use Kipchak\Cli\Scaffold\Add\PackageMetadata;
use Kipchak\Cli\Scaffold\Project;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Runs `kipchak add` against the starter project's own drivers.php and middlewares.php, with the metadata the
 * packages publish (copied from their composer.json into tests/fixtures).
 */
final class AddPackagesTest extends TestCase
{
    private const string ANTHROPIC_SAMPLE = "<?php\n\nuse function Kipchak\\Core\\env;\n\nreturn ['connections' => ['default' => ['api_key' => env('ANTHROPIC_API_KEY', ''), 'default_model' => env('ANTHROPIC_DEFAULT_MODEL', 'claude-sonnet-4-6')]]];\n";
    private const string HTTP_SAMPLE = "<?php\n\nreturn [];\n";

    /** @var array<string, PackageMetadata> */
    private array $known = [];

    /** @var array<string, list<string>> */
    private array $requires = [];

    protected function setUp(): void
    {
        foreach (glob(__DIR__ . '/../../fixtures/*.json') ?: [] as $file) {
            $composer = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $metadata = PackageMetadata::fromComposer($composer);
            self::assertNotNull($metadata, $file);
            $this->known[$metadata->name] = $metadata;
            $this->requires[$metadata->name] = array_values(array_filter(
                array_keys($composer['require'] ?? []),
                static fn (string $name): bool => str_starts_with($name, 'kipchak/'),
            ));
        }
    }

    private function starter(?string $drivers = null, ?string $middlewares = null): Project
    {
        return new Project(['Api\\' => 'api/'], '3.16', [
            'drivers/drivers.php' => $drivers ?? (string) file_get_contents(__DIR__ . '/../../fixtures/starter/drivers.php'),
            'middlewares/middlewares.php' => $middlewares ?? (string) file_get_contents(__DIR__ . '/../../fixtures/starter/middlewares.php'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function add(Project $project, string $package, bool $followDependencies = true): array
    {
        $packages = $followDependencies
            ? new DependencyResolver()->resolve($package, $this->requires, $this->known)
            : [$this->known[$package]];
        $changeSet = new AddPackages()->generate($project, $packages, $this->known, [
            'kipchak/driver-anthropic' => self::ANTHROPIC_SAMPLE,
            'kipchak/driver-http' => self::HTTP_SAMPLE,
        ]);

        $files = [];
        foreach ($changeSet->changes as $change) {
            $files[$change->path] = $change->content;
        }
        $files['#settings'] = json_encode($changeSet->settings);
        $files['#messages'] = implode("\n", $changeSet->messages);

        return $files;
    }

    public function testAddingAnthropicAlsoInitialisesHttpBeforeIt(): void
    {
        $files = $this->add($this->starter(), 'kipchak/driver-anthropic');

        $drivers = $files['drivers/drivers.php'];
        self::assertStringContainsString("use Kipchak\\Driver\\Anthropic\\Anthropic;\n", $drivers);
        self::assertStringContainsString("use Kipchak\\Driver\\Http\\Http;\n", $drivers);
        self::assertStringEndsWith(
            "Config::initialise(\$container);\nLogger::initialise(\$container);\nHttp::initialise(\$container);\nAnthropic::initialise(\$container);\n",
            $drivers,
        );
        self::assertSame(self::ANTHROPIC_SAMPLE, $files['config/kipchak.anthropic.php']);
        self::assertSame(self::HTTP_SAMPLE, $files['config/kipchak.http.php']);
        self::assertSame('[{"file":"config\/kipchak.anthropic.php","key":"ANTHROPIC_API_KEY"}]', $files['#settings']);
    }

    public function testAnHttpDriverAlreadyInitialisedIsLeftAlone(): void
    {
        $drivers = str_replace(
            "use Kipchak\\Driver\\Config\\Config;\n",
            "use Kipchak\\Driver\\Config\\Config;\nuse Kipchak\\Driver\\Http\\Http;\n",
            (string) file_get_contents(__DIR__ . '/../../fixtures/starter/drivers.php'),
        ) . "Http::initialise(\$container);\n";

        $files = $this->add($this->starter($drivers), 'kipchak/driver-anthropic');

        self::assertSame(1, substr_count($files['drivers/drivers.php'], 'Http::initialise('));
        self::assertStringEndsWith("Http::initialise(\$container);\nAnthropic::initialise(\$container);\n", $files['drivers/drivers.php']);
        self::assertArrayNotHasKey('config/kipchak.http.php', $files);
        self::assertStringContainsString('kipchak/driver-http is already initialised in drivers/drivers.php.', $files['#messages']);
    }

    public function testWithoutTheDependencyWalkHttpIsNotInitialised(): void
    {
        // The negative control for the first test: initialising only the named package leaves Http out.
        $files = $this->add($this->starter(), 'kipchak/driver-anthropic', followDependencies: false);

        self::assertStringNotContainsString('Http::initialise(', $files['drivers/drivers.php']);
    }

    public function testDoctrineFollowsTheCachesItRequires(): void
    {
        $drivers = $this->add($this->starter(), 'kipchak/driver-doctrine')['drivers/drivers.php'];

        $order = array_map(
            static fn (string $class): int => (int) strpos($drivers, "{$class}::initialise("),
            ['Logger', 'Filecache', 'Memcached', 'Doctrine'],
        );
        self::assertSame($order, array_values(array_unique($order)));
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order);
    }

    public function testMiddlewareGoesBetweenTheTwoErrorLines(): void
    {
        $middlewares = $this->add($this->starter(), 'kipchak/middleware-auth-key')['middlewares/middlewares.php'];

        self::assertMatchesRegularExpression(
            '/Error::initialise\(\$app\);\n\n\/\/ Other middleware goes here\.\nKey::initialise\(\$app\);\n\nError::initialise\(\$app\);\n$/',
            $middlewares,
        );
    }

    public function testMeteringIsPlacedBeforeAuthentication(): void
    {
        $project = $this->starter();
        $withKey = $this->add($project, 'kipchak/middleware-auth-key')['middlewares/middlewares.php'];
        $withBoth = $this->add($this->starter(middlewares: $withKey), 'kipchak/middleware-metering')['middlewares/middlewares.php'];

        self::assertLessThan(strpos($withBoth, 'Key::initialise('), strpos($withBoth, 'Metering::initialise('));
        self::assertLessThan(strrpos($withBoth, 'Error::initialise('), strpos($withBoth, 'Key::initialise('));
    }

    public function testSubashiIsPlacedAfterAuthentication(): void
    {
        $withKey = $this->add($this->starter(), 'kipchak/middleware-auth-key')['middlewares/middlewares.php'];
        $withBoth = $this->add($this->starter(middlewares: $withKey), 'kipchak/middleware-subashi')['middlewares/middlewares.php'];

        self::assertGreaterThan(strpos($withBoth, 'Key::initialise('), strpos($withBoth, 'Subashi::initialise('));
        self::assertGreaterThan(strpos($withBoth, 'Subashi::initialise('), strrpos($withBoth, 'Error::initialise('));
    }

    public function testRefusesACycle(): void
    {
        $this->requires['kipchak/driver-http'] = ['kipchak/driver-anthropic'];

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('kipchak/driver-anthropic -> kipchak/driver-http -> kipchak/driver-anthropic');

        new DependencyResolver()->resolve('kipchak/driver-anthropic', $this->requires, $this->known);
    }

    public function testAnExistingConfigurationIsKept(): void
    {
        $project = new Project(['Api\\' => 'api/'], '3.16', $this->starter()->files + ['config/kipchak.anthropic.php' => 'mine']);

        $files = $this->add($project, 'kipchak/driver-anthropic');

        self::assertArrayNotHasKey('config/kipchak.anthropic.php', $files);
        self::assertStringContainsString('config/kipchak.anthropic.php already exists and was kept.', $files['#messages']);
    }
}
