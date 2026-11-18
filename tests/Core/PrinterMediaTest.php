<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\PrinterMedia;
use TaxmodLabel\Core\Table;

final class PrinterMediaTest extends TestCase
{
    private const FIELD = 9;

    private function media(): PrinterMedia
    {
        return PrinterMedia::from(new Table([
            1 => [self::FIELD => ['10']],
            2 => [self::FIELD => ['11', '12', '11']],
            3 => [],
        ]), self::FIELD);
    }

    public function testAPrinterTakesOnlyItsOwnMedia(): void
    {
        $media = $this->media();
        self::assertTrue($media->allows(2, 12));
        self::assertFalse($media->allows(1, 12));
        self::assertFalse($media->allows(3, 10), 'a printer without media takes none (D-1441)');
        self::assertSame([11, 12], $media->of(2));
    }

    public function testThePrintersOfAMediumAreDerived(): void
    {
        self::assertSame([2], $this->media()->printersFor(11));
        self::assertSame([], $this->media()->printersFor(99));
    }
}
