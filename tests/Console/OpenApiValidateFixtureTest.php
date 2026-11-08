<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Console;

use Kipchak\Cli\Console\Application;
use Kipchak\Cli\Console\ExitCode;
use Kipchak\Cli\Tests\ProjectFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The validation fixture set (SR-2026-012, objective 4): the starter, and one project per check, each the starter
 * with one defect in its source. openapi:validate reports the check and exits with 1 on each, and with 0 on the
 * starter. Warnings (KIP-004, KIP-005, KIP-006) fail with --strict.
 *
 * The projects need the starter's dependencies installed, so the test runs when KIPCHAK_STARTER_DIR names a
 * kipchak/starter checkout with vendor/ (composer install). It copies it once and makes each defect in turn.
 */
final class OpenApiValidateFixtureTest extends TestCase
{
    private const string CONTROLLER = 'api/Controllers/v1/Sultans.php';
    private const string DTO = 'api/DataTransferObjects/v1/MamlukSultan.php';

    private static ?string $project = null;

    public static function setUpBeforeClass(): void
    {
        $starter = getenv('KIPCHAK_STARTER_DIR');
        if (!is_string($starter) || !is_file($starter . '/vendor/autoload.php')) {
            return;
        }
        self::$project = sys_get_temp_dir() . '/kipchak-cli-validate-' . bin2hex(random_bytes(6));
        exec('cp -R ' . escapeshellarg($starter) . ' ' . escapeshellarg(self::$project), result_code: $code);
        if ($code !== 0) {
            self::fail('The starter could not be copied.');
        }
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
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function validate(bool $strict = false): array
    {
        $tester = new CommandTester(new Application()->find('openapi:validate'));
        $code = $tester->execute(['--project-dir' => self::$project, '--format' => 'json', '--strict' => $strict]);

        return [$code, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function testTheStarterIsValid(): void
    {
        [$code, $result] = $this->validate();

        self::assertSame(ExitCode::SUCCESS, $code, json_encode($result, JSON_PRETTY_PRINT) ?: '');
        self::assertSame(0, $result['errors']);
    }

    public function testTheStarterPassesStrictValidation(): void
    {
        [$code, $result] = $this->validate(strict: true);

        self::assertSame(ExitCode::SUCCESS, $code, json_encode($result, JSON_PRETTY_PRINT) ?: '');
        self::assertSame([], $result['findings']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}> check => file, the text replaced, its
     *     replacement (a file that does not exist is created with the replacement)
     */
    public static function defects(): array
    {
        return [
            'OAS-001' => ['OAS-001', self::CONTROLLER, "schema: new OA\\Schema(type: 'integer', example: 4)),", "schema: new OA\\Schema(type: 'integer', example: 4), style: 'form'),"],
            'OAS-002' => ['OAS-002', self::CONTROLLER, "                response: 200,\n                description: 'Every sultan, in order of reign.',", "                description: 'Every sultan, in order of reign.',"],
            'OAS-003' => ['OAS-003', self::CONTROLLER, "JsonContent(ref: '#/components/schemas/MamlukSultan')", "JsonContent(ref: '#/components/schemas/Missing')"],
            'KIP-001' => ['KIP-001', 'routes/v1/unlisted.php', '', "<?php\n\n\$app->get('/v1/unlisted', fn (\$request, \$response) => \$response);\n"],
            'KIP-002' => ['KIP-002', self::CONTROLLER, "        parameters: [\n            new OA\\Parameter(name: 'id', in: 'path', required: true, schema: new OA\\Schema(type: 'integer', example: 4)),\n        ],\n", ''],
            'KIP-003' => ['KIP-003', self::CONTROLLER, "        summary: 'List the sultans',\n", ''],
            'KIP-004' => ['KIP-004', self::CONTROLLER, "items: new OA\\Items(ref: '#/components/schemas/MamlukSultan')", "items: new OA\\Items(properties: [new OA\\Property(property: 'name', type: 'string')])"],
            'KIP-005' => ['KIP-005', self::DTO, "#[OA\\Property(description: 'Name by which the sultan is known.', example: 'Baybars')]", "#[OA\\Property(example: 'Baybars')]"],
            // The generator no longer registers Envelope, so the data-only successes misstate what the API sends.
            'KIP-006' => ['KIP-006', 'api/Utils/OpenApi.php', "\n            ->withProcessorPipeline(fn (Pipeline \$pipeline) => \$pipeline->add(new Envelope(errors: true)))", ''],
        ];
    }

    /**
     * @dataProvider defects
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('defects')]
    public function testEachDefectFailsValidationWithItsCheck(string $check, string $file, string $search, string $replace): void
    {
        $path = self::$project . '/' . $file;
        $original = is_file($path) ? (string) file_get_contents($path) : null;
        if ($original === null) {
            file_put_contents($path, $replace);
        } else {
            self::assertSame(1, substr_count($original, $search), "The starter's {$file} no longer contains the text this defect replaces.");
            file_put_contents($path, str_replace($search, $replace, $original));
        }

        try {
            [$code, $result] = $this->validate(strict: in_array($check, ['KIP-004', 'KIP-005', 'KIP-006'], true));
        } finally {
            $original === null ? unlink($path) : file_put_contents($path, $original);
        }

        self::assertSame(ExitCode::FAILURE, $code);
        self::assertContains($check, array_column($result['findings'], 'check'), json_encode($result['findings'], JSON_PRETTY_PRINT) ?: '');
    }
}
