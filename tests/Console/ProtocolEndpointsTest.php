<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Console;

use Kipchak\Cli\Console\Application;
use Kipchak\Cli\Console\ExitCode;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Protocol endpoints in a real project: a starter with kipchak/driver-mcp installed, whose /mcp route is declared by
 * the driver under extra.kipchak.protocol-endpoints. resources/inspect.php checks each declaration with the project's
 * own autoloader (A7), and openapi:validate lists a declared route as a notice instead of a KIP-001 error.
 *
 * Runs when KIPCHAK_STARTER_MCP_DIR names a starter checkout with vendor/ installed that includes kipchak/driver-mcp.
 * Each case edits vendor/composer/installed.json in a copy, the file the inspection script reads.
 */
final class ProtocolEndpointsTest extends TestCase
{
    private const string DRIVER = 'kipchak/driver-mcp';

    private static ?string $project = null;

    private static string $installed = '';

    public static function setUpBeforeClass(): void
    {
        $starter = getenv('KIPCHAK_STARTER_MCP_DIR');
        if (!is_string($starter) || !is_dir($starter . '/vendor/kipchak/driver-mcp')) {
            return;
        }
        self::$project = sys_get_temp_dir() . '/kipchak-cli-protocol-' . bin2hex(random_bytes(6));
        exec('cp -R ' . escapeshellarg($starter) . ' ' . escapeshellarg(self::$project), result_code: $code);
        if ($code !== 0) {
            self::fail('The starter could not be copied.');
        }
        self::$installed = (string) file_get_contents(self::$project . '/vendor/composer/installed.json');
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
            self::markTestSkipped('Set KIPCHAK_STARTER_MCP_DIR to a kipchak/starter checkout with kipchak/driver-mcp installed.');
        }
        file_put_contents(self::$project . '/vendor/composer/installed.json', self::$installed);
    }

    /**
     * @param mixed $declaration the value of the driver's extra.kipchak.protocol-endpoints; null removes it
     */
    private static function declare(mixed $declaration): void
    {
        $data = json_decode(self::$installed, true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['packages'] as &$package) {
            if ($package['name'] === self::DRIVER) {
                if ($declaration === null) {
                    unset($package['extra']['kipchak']['protocol-endpoints']);
                } else {
                    $package['extra']['kipchak']['protocol-endpoints'] = $declaration;
                }
            }
        }
        unset($package);
        file_put_contents(self::$project . '/vendor/composer/installed.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function validate(): array
    {
        $tester = new CommandTester(new Application()->find('openapi:validate'));
        $code = $tester->execute(['--project-dir' => self::$project, '--format' => 'json', '--strict' => true]);

        return [$code, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private static function summary(array $result, string $severity): array
    {
        return array_values(array_map(
            static fn (array $f): string => "{$f['check']} {$f['location']}",
            array_filter($result['findings'], static fn (array $f): bool => $f['severity'] === $severity),
        ));
    }

    public function testTheDriversDeclarationPassesStrictValidationWithANoticePerMethod(): void
    {
        [$code, $result] = self::validate();

        self::assertSame(ExitCode::SUCCESS, $code, json_encode($result, JSON_PRETTY_PRINT) ?: '');
        self::assertSame(0, $result['errors']);
        self::assertSame(0, $result['warnings']);
        self::assertSame(6, $result['notices']);
        self::assertContains('KIP-001 POST /mcp', self::summary($result, 'notice'));
    }

    public function testWithoutTheDeclarationTheMcpRoutesAreErrors(): void
    {
        self::declare(null);

        [$code, $result] = self::validate();

        self::assertSame(ExitCode::FAILURE, $code);
        self::assertSame(6, $result['errors']);
        self::assertContains('KIP-001 POST /mcp', self::summary($result, 'error'));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function badDeclarations(): array
    {
        return [
            'a class outside the package\'s namespace' => [['Api\\Mcp\\StatusTools'], "outside the package's own namespace"],
            'the route\'s handler, declared by a package it is not in' => [['Slim\\App'], "outside the package's own namespace"],
            'a wildcard' => [['Kipchak\\Driver\\Mcp\\*'], 'is a wildcard'],
            'a class that does not exist' => [['Kipchak\\Driver\\Mcp\\Missing'], 'cannot be loaded'],
            'not a class name' => [[42], 'is not a class name'],
        ];
    }

    #[DataProvider('badDeclarations')]
    public function testABadDeclarationIsAnErrorNamingThePackageAndSkipsNothing(mixed $declaration, string $problem): void
    {
        self::declare($declaration);

        [$code, $result] = self::validate();

        self::assertSame(ExitCode::FAILURE, $code);
        self::assertContains('KIP-007 ' . self::DRIVER, self::summary($result, 'error'));
        $kip007 = array_values(array_filter($result['findings'], static fn (array $f): bool => $f['check'] === 'KIP-007'));
        self::assertStringContainsString($problem, $kip007[0]['message']);
        self::assertContains('KIP-001 POST /mcp', self::summary($result, 'error'), 'The routes are still checked');
    }
}
