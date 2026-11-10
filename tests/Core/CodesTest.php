<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\Code\Code128;
use TaxmodLabel\Core\Code\Code39;
use TaxmodLabel\Core\Code\DataMatrix;
use TaxmodLabel\Core\Code\Ean13;
use TaxmodLabel\Core\Code\QrCode;

/**
 * The codes as their standards write them; that they scan is shown by scripts/dev/scan-check.py with a real decoder.
 */
final class CodesTest extends TestCase
{
    public function testCode128PacksDigitRunsAndAddsTheCheck(): void
    {
        // «M-» in B, «45704» → B «4» then C «57» «04»? No: a run of five in B keeps its first digit and packs the rest.
        $values = Code128::encode('M-45704')->values();
        self::assertSame(104, $values[0], 'starts in set B');
        self::assertSame(106, end($values), 'ends with stop');
        self::assertContains(99, $values, 'switches to C for the digit run');

        $digits = Code128::encode('1234567890')->values();
        self::assertSame([105, 12, 34, 56, 78, 90], array_slice($digits, 0, 6), 'all digits: set C from the start');
        $sum = 105;
        foreach ([12, 34, 56, 78, 90] as $i => $v) {
            $sum += ($i + 1) * $v;
        }
        self::assertSame($sum % 103, $digits[6]);
    }

    public function testCode128WidthsAddUpPerSymbol(): void
    {
        $code   = Code128::encode('AB');
        $widths = $code->widths();
        // start + A + B + check = 4 × 11 modules, stop 13.
        self::assertSame(4 * 11 + 13, array_sum($widths));
    }

    public function testCode128RefusesWhatItCannotCarry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Code128::encode('Ä');
    }

    public function testEan13CheckDigit(): void
    {
        self::assertSame(1, Ean13::check('400638133393'));
        self::assertSame('4006381333931', Ean13::encode('400638133393')->humanText());
        self::assertSame(95, array_sum(Ean13::encode('4006381333931')->widths()));
    }

    public function testEan13RefusesAWrongCheckDigit(): void
    {
        $this->expectExceptionMessage('check digit');
        Ean13::encode('4006381333932');
    }

    public function testCode39(): void
    {
        $widths = Code39::encode('A1')->widths();
        // *A1* = 4 signs × (6 narrow + 3 wide × 3) + 3 gaps.
        self::assertSame(4 * 15 + 3, array_sum($widths));
        $this->expectException(\InvalidArgumentException::class);
        Code39::encode('abc');
    }

    public function testDataMatrixSizes(): void
    {
        $code = DataMatrix::encode('M-45704');
        self::assertSame(12, $code->width(), '7 characters → 6 codewords → 12 × 12');
        self::assertTrue($code->isDark(0, 11), 'solid edge bottom left');
        self::assertTrue($code->isDark(0, 0), 'clock edge starts dark');
        self::assertFalse($code->isDark(1, 0), 'clock edge alternates');

        $shapes = array_map(static fn (DataMatrix $c): string => $c->height() . 'x' . $c->width(), DataMatrix::candidates('M-45704', true));
        self::assertContains('8x18', $shapes);
    }

    public function testDataMatrixFindsTheLargeSizes(): void
    {
        $code = DataMatrix::encode(str_repeat('abcdefghij', 20));
        self::assertSame(52, $code->width(), '200 characters need two interleaved blocks');
    }

    public function testQrStaysAsBefore(): void
    {
        $qr = QrCode::encode('M-45704');
        self::assertSame(21, $qr->width());
        self::assertSame(4, $qr->quietModules());
    }
}
