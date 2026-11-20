<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\Pdf\PostageKeep;

/** D-1513: the postage store takes PDFs up to 2 MB under a plain key and lets them go 14 days after the first print. */
final class PostageKeepTest extends TestCase
{
    public function testTakesAPdfUnderAPlainKey(): void
    {
        PostageKeep::check('%PDF-1.4 …');
        self::assertSame('taxship-87301', PostageKeep::key('taxship-87301'));
    }

    public function testRefusesWhatIsNoPdf(): void
    {
        $this->expectException(LabelError::class);
        PostageKeep::check('<html>');
    }

    public function testRefusesMoreThanTwoMegabytes(): void
    {
        $this->expectException(LabelError::class);
        PostageKeep::check('%PDF' . str_repeat('x', PostageKeep::MAX_BYTES));
    }

    public function testRefusesAKeyThatCouldLeaveTheFolder(): void
    {
        $this->expectException(LabelError::class);
        PostageKeep::key('../wp-config');
    }

    public function testGoesFourteenDaysAfterTheFirstPrint(): void
    {
        $day = 86400;
        $now = 100 * $day;

        self::assertFalse(PostageKeep::expired(null, $now), 'Unprinted stays.');
        self::assertFalse(PostageKeep::expired($now - 13 * $day, $now));
        self::assertTrue(PostageKeep::expired($now - 15 * $day, $now));
    }
}
