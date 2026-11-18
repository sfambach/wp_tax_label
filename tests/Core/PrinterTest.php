<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\LabelPrinter;

/** D-1333: the printer's pause travels with its jobs — it came from the modeller's printer and must not get lost (D-1296). */
final class PrinterTest extends TestCase
{
    public function testThePauseIsReadAndKeptWithinItsBounds(): void
    {
        self::assertSame(5, LabelPrinter::fromArray(['queue' => 'Phomemo_M220L', 'pause' => '5'])->pauseSeconds);
        self::assertSame(0, LabelPrinter::fromArray(['queue' => 'QL-810W'])->pauseSeconds);
        self::assertSame(LabelPrinter::MAX_PAUSE, LabelPrinter::fromArray(['queue' => 'X', 'pause' => '9999'])->pauseSeconds);
        self::assertSame(5, LabelPrinter::fromArray(LabelPrinter::fromArray(['queue' => 'X', 'pause' => 5])->toArray())->pauseSeconds);
    }
}
