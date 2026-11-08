<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Scaffold\Make;

use Kipchak\Cli\Scaffold\Make\ResponseStyle;
use Kipchak\Cli\Scaffold\Validate\Inspection;
use PHPUnit\Framework\TestCase;

/**
 * The short form is written only on evidence that the project's own generator registers Envelope; every other case
 * gets the long form. starter-4.4-inspection.json is resources/inspect.php's output for kipchak/starter 4.4.1, and
 * starter-inspection.json its output for 4.3, whose generator does not register Envelope.
 */
final class ResponseStyleTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function inspection(string $fixture): array
    {
        return json_decode((string) file_get_contents(__DIR__ . "/../../fixtures/validate/{$fixture}"), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $inspection
     */
    private static function style(array $inspection): ResponseStyle
    {
        return ResponseStyle::fromInspection(Inspection::fromJson((string) json_encode($inspection, JSON_PRESERVE_ZERO_FRACTION)));
    }

    public function testAProjectWhoseGeneratorRegistersEnvelopeGetsTheShortForm(): void
    {
        $style = self::style(self::inspection('starter-4.4-inspection.json'));

        self::assertTrue($style->envelope);
        self::assertTrue($style->errorEnvelope);
        self::assertSame("                content: new OA\\JsonContent(ref: '#/components/schemas/Order'),", $style->content('OA', 200, "ref: '#/components/schemas/Order'"));
        self::assertSame("            new OA\\Response(response: 404, description: 'Not found.'),", $style->notFound('OA', 'Not found.'));
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function withoutEvidence(): array
    {
        return [
            'the generator does not register Envelope (starter 4.3)' => [static fn (array $i): array => self::inspection('starter-inspection.json') + ['pipeline' => 'project', 'envelopeAvailable' => true]],
            'the CLI generated the document itself' => [static fn (array $i): array => ['pipeline' => 'fallback'] + $i],
            'the pipeline is not reported (an older inspection script)' => [static function (array $i): array {
                unset($i['pipeline']);

                return $i;
            }],
            'core has no Envelope' => [static fn (array $i): array => ['envelopeAvailable' => false] + $i],
            'the API defines its own Success schema' => [static function (array $i): array {
                $i['document']['components']['schemas']['Success']['description'] = 'Our own envelope.';

                return $i;
            }],
            'no response refers to Success yet' => [static function (array $i): array {
                $i['document']['paths'] = [];

                return $i;
            }],
            'no document' => [static function (array $i): array {
                unset($i['document']);

                return $i;
            }],
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('withoutEvidence')]
    public function testWithoutEvidenceTheLongFormIsWritten(callable $change): void
    {
        $style = self::style($change(self::inspection('starter-4.4-inspection.json')));

        self::assertFalse($style->envelope);
        self::assertFalse($style->errorEnvelope);
        self::assertStringContainsString("Property(property: 'code', type: 'integer', example: 201)", $style->content('OA', 201, "ref: '#/components/schemas/Order'"));
        self::assertSame("            new OA\\Response(response: 404, ref: '#/components/responses/NotFound'),", $style->notFound('OA', 'Not found.'));
    }

    public function testNoInspectionGivesTheLongForm(): void
    {
        self::assertFalse(ResponseStyle::fromInspection(null)->envelope);
    }

    public function testAProjectThatDocumentsSuccessesButNotErrorsKeepsTheNotFoundReference(): void
    {
        // Envelope(errors: false): the processor states successes, but an error response with no content stays empty.
        $inspection = self::inspection('starter-4.4-inspection.json');
        unset($inspection['document']['components']['schemas']['Error']);
        $inspection['document']['paths'] = json_decode(str_replace('#/components/schemas/Error', '#/components/schemas/Gone', (string) json_encode($inspection['document']['paths'])), true);
        $style = self::style($inspection);

        self::assertTrue($style->envelope);
        self::assertFalse($style->errorEnvelope);
    }
}
