<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Console;

use Kipchak\Cli\Console\ExitCode;
use Kipchak\Cli\Console\OpenApiValidateCommand;
use Kipchak\Cli\Console\RoutesCommand;
use Kipchak\Cli\Project\Inspector;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A project that runs more than one app: the starter, plus a second app (tests/fixtures/multi-app) that the project
 * declares in its composer.json under extra.kipchak.apps, with its operations in the same document. Each declared
 * app is booted in a process of its own; KIP-001 checks the routes of every app; a declaration that gives no routes
 * is KIP-008; a route served by two apps is KIP-009.
 *
 * Runs when KIPCHAK_STARTER_DIR names a kipchak/starter checkout with vendor/ installed. It copies it once, adds the
 * fixture files, and sets the declaration for each case.
 */
final class MultiAppValidateTest extends TestCase
{
    private const string RECEIVE = 'Api\\Receive\\ReceiveApp::boot';

    private static ?string $project = null;

    private static string $composer = '';

    public static function setUpBeforeClass(): void
    {
        $starter = getenv('KIPCHAK_STARTER_DIR');
        if (!is_string($starter) || !is_file($starter . '/vendor/autoload.php')) {
            return;
        }
        self::$project = sys_get_temp_dir() . '/kipchak-cli-multi-app-' . bin2hex(random_bytes(6));
        exec('cp -R ' . escapeshellarg($starter) . ' ' . escapeshellarg(self::$project)
            . ' && cp -R ' . escapeshellarg(__DIR__ . '/../fixtures/multi-app') . '/. ' . escapeshellarg(self::$project), result_code: $code);
        if ($code !== 0) {
            self::fail('The starter could not be copied.');
        }
        self::$composer = (string) file_get_contents(self::$project . '/composer.json');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$project !== null) {
            ProjectFixture::remove(self::$project);
        }
    }

    protected function setUp(): void
    {
        if (self::$project === null) {
            self::markTestSkipped('Set KIPCHAK_STARTER_DIR to a kipchak/starter checkout with vendor/ installed.');
        }
        file_put_contents(self::$project . '/composer.json', self::$composer);
        foreach (['hang.pid', 'hang-main'] as $file) {
            if (is_file(self::$project . '/' . $file)) {
                unlink(self::$project . '/' . $file);
            }
        }
    }

    /**
     * @param list<mixed> $apps the value of extra.kipchak.apps
     */
    private static function declare(array $apps): void
    {
        $data = json_decode(self::$composer, true, flags: JSON_THROW_ON_ERROR);
        $data['extra']['kipchak']['apps'] = $apps;
        file_put_contents(self::$project . '/composer.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function validate(bool $strict = true, ?Inspector $inspector = null): array
    {
        $tester = new CommandTester(new OpenApiValidateCommand($inspector ?? new Inspector()));
        $code = $tester->execute(['--project-dir' => self::$project, '--format' => 'json', '--strict' => $strict]);

        return [$code, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)];
    }

    /**
     * @return array<string, list<string>> 'METHOD pattern' of each route, by app
     */
    private static function routesByApp(?Inspector $inspector = null): array
    {
        $tester = new CommandTester(new RoutesCommand($inspector ?? new Inspector()));
        $tester->execute(['--project-dir' => self::$project, '--format' => 'json']);
        $byApp = [];
        foreach (json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['routes'] as $route) {
            $byApp[$route['app'] ?? '(none)'][] = implode('|', $route['methods']) . ' ' . $route['pattern'];
        }

        return $byApp;
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string> 'CHECK SEVERITY location', apart from the starter's MCP endpoint notices
     */
    private static function summary(array $result): array
    {
        return array_values(array_map(
            static fn (array $f): string => "{$f['check']} {$f['severity']} {$f['location']}",
            array_filter($result['findings'], static fn (array $f): bool => !str_starts_with($f['message'], 'Not checked: a protocol endpoint of kipchak/driver-mcp')),
        ));
    }

    /**
     * T1.
     */
    public function testADeclaredSecondAppWithItsOperationsPassesStrictValidation(): void
    {
        self::declare([self::RECEIVE]);

        [$code, $result] = self::validate();

        self::assertSame([], self::summary($result));
        self::assertSame(ExitCode::SUCCESS, $code);
    }

    /**
     * T1's control: without the declaration, the second app's operations have no route.
     */
    public function testWithoutTheDeclarationTheSecondAppsOperationsAreKip001(): void
    {
        [$code, $result] = self::validate();

        self::assertSame(['KIP-001 error PUT /receive/v1/files/{name}', 'KIP-001 error GET /receive/v1/health'], self::summary($result));
        self::assertSame(ExitCode::FAILURE, $code);
    }

    /**
     * T2: each app boots in its own process, so neither sees the flag the other's boot sets.
     */
    public function testEachAppIsBootedInItsOwnProcess(): void
    {
        self::declare([self::RECEIVE]);

        $routes = self::routesByApp();

        self::assertSame(['PUT /receive/v1/files/{name}', 'GET /receive/v1/health'], $routes[self::RECEIVE]);
        self::assertNotContains('GET /v1/leaked', $routes['main']);
        self::assertContains('GET /status', $routes['main']);
    }

    /**
     * T2's control: with both apps booted in one process (the other app's boot prepended to each inspection), the
     * route lists are not the expected ones. This shows that T2 would detect shared processes.
     */
    public function testBootedInOneProcessTheRouteListsDiffer(): void
    {
        self::declare([self::RECEIVE]);
        $prepend = self::$project . '/prepend-other-app.php';
        file_put_contents($prepend, '<?php require getcwd() . "/vendor/autoload.php"; '
            . 'in_array("--app=' . addslashes(self::RECEIVE) . '", $_SERVER["argv"], true) ? Kipchak\Core\Api::boot() : Api\Receive\ReceiveApp::boot();');
        $shared = new class extends Inspector {
            public static string $prepend = '';

            protected function run(array $command, string $workingDirectory): array
            {
                return parent::run([$command[0], '-d', 'auto_prepend_file=' . self::$prepend, ...array_slice($command, 1)], $workingDirectory);
            }
        };
        $shared::$prepend = $prepend;

        try {
            $routes = self::routesByApp($shared);
        } finally {
            unlink($prepend);
        }

        self::assertContains('GET /v1/leaked', $routes['main']);
        self::assertContains('GET /receive/v1/leaked', $routes[self::RECEIVE]);
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string}> the declaration, the location of its KIP-008 finding,
     *     and a part of its message
     */
    public static function badDeclarations(): array
    {
        return [
            'an unknown class' => ['Api\\Receive\\Missing::boot', 'Api\\Receive\\Missing::boot', 'which cannot be loaded'],
            'an unknown method' => ['Api\\Receive\\BadApps::missing', 'Api\\Receive\\BadApps::missing', 'does not have'],
            'a method that is not static' => ['Api\\Receive\\BadApps::instance', 'Api\\Receive\\BadApps::instance', 'not public and static'],
            'a private method' => ['Api\\Receive\\BadApps::hidden', 'Api\\Receive\\BadApps::hidden', 'not public and static'],
            'a required argument' => ['Api\\Receive\\BadApps::withArgument', 'Api\\Receive\\BadApps::withArgument', 'takes a required argument'],
            'an exception while booting' => ['Api\\Receive\\BadApps::throws', 'Api\\Receive\\BadApps::throws', 'threw RuntimeException while booting: The receive app is not configured.'],
            'a value that is not a Slim\\App' => ['Api\\Receive\\BadApps::notAnApp', 'Api\\Receive\\BadApps::notAnApp', 'returned stdClass, not a Slim\\App'],
            'a fatal error while booting' => ['Api\\Receive\\BadApps::fatal', 'Api\\Receive\\BadApps::fatal', 'Allowed memory size'],
            'an exit while booting' => ['Api\\Receive\\BadApps::exits', 'Api\\Receive\\BadApps::exits', 'ended with exit code 0 before reporting its routes'],
            'a class without a method' => ['Api\\Receive\\ReceiveApp', 'Api\\Receive\\ReceiveApp', 'is not a Class::method name'],
            'not a string' => [42, '42', 'is not a Class::method name'],
        ];
    }

    /**
     * T3: each bad declaration fails the run with KIP-008 naming the entry; the validator itself finishes, and the
     * other apps are still checked.
     */
    #[DataProvider('badDeclarations')]
    public function testABadDeclarationIsKip008NamingTheEntry(mixed $declaration, string $location, string $message): void
    {
        self::declare([$declaration, self::RECEIVE]);

        [$code, $result] = self::validate();

        self::assertSame(["KIP-008 error {$location}"], self::summary($result));
        $finding = array_values(array_filter($result['findings'], static fn (array $f): bool => $f['check'] === 'KIP-008'))[0];
        self::assertStringContainsString($message, $finding['message']);
        self::assertSame(ExitCode::FAILURE, $code);
    }

    /**
     * T3, the hang: the declared app's process is stopped at the time limit (lowered here to 3 seconds), ended, and
     * reported as KIP-008.
     */
    public function testADeclaredAppThatHangsIsStoppedEndedAndKip008(): void
    {
        self::declare(['Api\\Receive\\BadApps::hangs', self::RECEIVE]);

        $started = microtime(true);
        [$code, $result] = self::validate(inspector: new Inspector(timeout: 3));

        self::assertLessThan(30, microtime(true) - $started);
        self::assertSame(['KIP-008 error Api\\Receive\\BadApps::hangs'], self::summary($result));
        self::assertStringContainsString('was stopped after 3 seconds', $result['findings'][array_search('KIP-008', array_column($result['findings'], 'check'), true)]['message']);
        self::assertSame(ExitCode::FAILURE, $code);
        $pid = (int) file_get_contents(self::$project . '/hang.pid');
        self::assertGreaterThan(0, $pid);
        self::assertDirectoryDoesNotExist("/proc/{$pid}", 'The inspection process is still running, or was not reaped.');
    }

    /**
     * The main app's boot hanging is "the project could not be inspected", exit code 2, as any failure of the main
     * inspection is.
     */
    public function testAMainAppThatHangsIsStoppedWithExitCode2(): void
    {
        touch(self::$project . '/hang-main');
        file_put_contents(self::$project . '/routes/hang-main.php', '<?php while (is_file("hang-main")) { sleep(1); }');

        try {
            $tester = new CommandTester(new OpenApiValidateCommand(new Inspector(timeout: 3)));
            $started = microtime(true);
            $code = $tester->execute(['--project-dir' => self::$project]);
        } finally {
            unlink(self::$project . '/routes/hang-main.php');
        }

        self::assertLessThan(30, microtime(true) - $started);
        self::assertSame(ExitCode::CANNOT_RUN, $code);
        self::assertStringContainsString('stopped after 3 seconds', $tester->getDisplay());
    }

    /**
     * T4: a route served by two apps is KIP-009, a warning.
     */
    public function testARouteServedByTwoAppsIsKip009(): void
    {
        self::declare(['Api\\Receive\\ReceiveApp::bootWithStatus']);

        [$strictCode, $result] = self::validate();
        [$code] = self::validate(strict: false);

        self::assertSame(['KIP-009 warning GET /status'], self::summary($result));
        self::assertSame(ExitCode::FAILURE, $strictCode);
        self::assertSame(ExitCode::SUCCESS, $code);
    }

    /**
     * T5: a route of the second app with no operation is KIP-001, naming that app.
     */
    public function testARouteOfTheSecondAppWithNoOperationIsKip001NamingTheApp(): void
    {
        self::declare(['Api\\Receive\\ReceiveApp::bootWithUnlisted']);

        [$code, $result] = self::validate();

        self::assertSame(['KIP-001 error GET /receive/v1/unlisted'], self::summary($result));
        self::assertStringContainsString('of the app Api\\Receive\\ReceiveApp::bootWithUnlisted', $result['findings'][array_search('GET /receive/v1/unlisted', array_column($result['findings'], 'location'), true)]['message']);
        self::assertSame(ExitCode::FAILURE, $code);
    }

    /**
     * T6: with no declaration, the inspection carries no app, and `kipchak routes` has no app column.
     */
    public function testWithoutADeclarationTheOutputHasNoApp(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../resources/inspect.php') . ' ' . escapeshellarg((string) self::$project) . ' 2>/dev/null', $lines);
        $inspection = json_decode(implode("\n", $lines), true, flags: JSON_THROW_ON_ERROR);
        $tester = new CommandTester(new RoutesCommand());
        $tester->execute(['--project-dir' => self::$project]);

        self::assertArrayNotHasKey('apps', $inspection);
        self::assertSame([], array_values(array_filter($inspection['routes'], static fn (array $r): bool => array_key_exists('app', $r))));
        self::assertSame(['(none)'], array_keys(self::routesByApp()));
        self::assertStringStartsWith('GET ', $tester->getDisplay());
        self::assertStringNotContainsString('main', $tester->getDisplay());
    }

    /**
     * With a declaration, `kipchak routes` names the app of each route.
     */
    public function testWithADeclarationRoutesNamesTheAppOfEachRoute(): void
    {
        self::declare([self::RECEIVE]);
        $tester = new CommandTester(new RoutesCommand());

        $code = $tester->execute(['--project-dir' => self::$project]);

        self::assertSame(ExitCode::SUCCESS, $code);
        self::assertMatchesRegularExpression('/^main\s+GET\s+\/status\s/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^Api\\\\Receive\\\\ReceiveApp::boot\s+PUT\s+\/receive\/v1\/files\/\{name\}\s/m', $tester->getDisplay());
    }
}
