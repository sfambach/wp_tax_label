<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\Pdf\PostageText;

/** D-1511: the server-side reader of a postage PDF's text, moved from the eBay module with its case. */
final class PostageTextTest extends TestCase
{
    public function testReadsTheLinesOfALabel(): void
    {
        $font = gzcompress("begincmap\n3 beginbfchar\n<0001> <0020>\n<0002> <004D>\n<0003> <00DC>\nendbfchar\nendcmap");
        $page = gzcompress("BT /F1 10 Tf [<00020003>] TJ 0 -12 Td (12345 ORT) Tj ET");
        $pdf = "%PDF-1.4\n1 0 obj << /Font << /F1 2 0 R >> >> endobj\n2 0 obj << /Type /Font /ToUnicode 3 0 R >> endobj\n"
            . "3 0 obj << /Filter /FlateDecode >>\nstream\n" . $font . "\nendstream\nendobj\n"
            . "4 0 obj << /Filter /FlateDecode >>\nstream\n" . $page . "\nendstream\nendobj\n";

        self::assertSame(['MÜ', '12345 ORT'], PostageText::of($pdf));
    }

    public function testAnythingElseReadsNothing(): void
    {
        self::assertSame([], PostageText::of('not a pdf'));
    }
}
