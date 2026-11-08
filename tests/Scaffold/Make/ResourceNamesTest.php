<?php

declare(strict_types=1);

namespace Kipchak\Cli\Tests\Scaffold\Make;

use InvalidArgumentException;
use Kipchak\Cli\Scaffold\Make\Resource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The model has the resource's name and imports the DTO, so the two cannot share a name: PHP refuses to compile it.
 */
final class ResourceNamesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function namesWithTheirPlurals(): array
    {
        return [
            'a plain noun' => ['Pen', 'Pens'],
            'ending in a consonant and y' => ['Category', 'Categories'],
            'ending in a vowel and y' => ['Key', 'Keys'],
            'ending in ss' => ['Address', 'Addresses'],
            'ending in ch' => ['Batch', 'Batches'],
        ];
    }

    #[DataProvider('namesWithTheirPlurals')]
    public function testANameThatIsItsOwnSingularIsRefusedWithThePlural(string $name, string $plural): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Did you mean {$plural}?");

        new Resource($name);
    }

    public function testTheRefusalNamesTheOptions(): void
    {
        $this->expectExceptionMessage('--dto or --singular, such as --dto=PenItem');

        new Resource('Pen');
    }

    public function testADtoDifferingOnlyInCaseIsRefused(): void
    {
        // PHP class names are case-insensitive, so this collides just as the same spelling does.
        $this->expectException(InvalidArgumentException::class);

        new Resource('Pens', dto: 'pens');
    }

    public function testControlAPluralIsAccepted(): void
    {
        self::assertSame('Pen', new Resource('Pens')->dto);
    }

    public function testControlTheSameNameIsAcceptedWithItsOwnDto(): void
    {
        self::assertSame('PenItem', new Resource('Pen', dto: 'PenItem')->dto);
    }

    public function testControlTheSameNameIsAcceptedWithItsOwnSingular(): void
    {
        self::assertSame('Sheep', new Resource('Flock', singular: 'Sheep')->dto);
        self::assertSame('PenItem', new Resource('Pen', singular: 'PenItem')->dto);
    }
}
