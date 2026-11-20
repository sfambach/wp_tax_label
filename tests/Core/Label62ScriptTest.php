<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;

/** D-1507: the 62 mm postage print keeps eBay's crop sizes, ships its pdf.js and never sends the PDF anywhere. */
final class Label62ScriptTest extends TestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/assets/label62.js');
    }

    public function testCropSizesStayAsEbayHadThem(): void
    {
        $script = self::script();

        foreach (['var TAPE_MM = 62;', 'var PRINT_MM = 58;', 'var PAGE_MM = 100;', 'var FREE_MM = PAGE_MM - 9;', 'var DPI = 300;', 'crop(canvas, Math.round(DPI / 25.4 * 12))', 'section{padding:4.5mm 2mm}'] as $line) {
            self::assertStringContainsString($line, $script);
        }
    }

    public function testPdfStaysInTheBrowser(): void
    {
        $script = self::script();

        self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(|XMLHttpRequest|sendBeacon|FormData/', $script);
        self::assertDoesNotMatchRegularExpression('#https?://#', $script);
        self::assertStringNotContainsString('print()', str_replace('view.print()', '', $script), 'Printing happens only in print(), after a click.');
    }

    public function testOneBuildingBlockChoosesAndReadsThePdf(): void
    {
        $script = self::script();

        foreach (['taxmodLabel62.pick = pick;', 'taxmodLabel62.lines = lines;', 'getTextContent()'] as $line) {
            self::assertStringContainsString($line, $script);
        }

        self::assertStringContainsString("TEXT_FILTER = 'taxmod_label_pdf_text'", (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Label62Script.php'));
    }

    public function testRunSettlesOnlyAfterThePrintDialog(): void
    {
        $script = self::script();

        // Stefan 20.11. «er druckt nicht»: Versand reloaded the page before the print started.
        self::assertStringContainsString('return print(pages, caption).then(', $script);
        self::assertMatchesRegularExpression('/view\.print\(\);\s*done\(\);/', $script);
    }

    public function testPdfJsShipsWithItsFixedVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $class = (string) file_get_contents($root . '/src/WordPress/Label62Script.php');
        self::assertMatchesRegularExpression("/PDFJS_VERSION = '([0-9.]+)'/", $class);
        preg_match("/PDFJS_VERSION = '([0-9.]+)'/", $class, $version);

        foreach (['pdf.min.js', 'pdf.worker.min.js'] as $file) {
            self::assertStringContainsString('"' . $version[1] . '"', (string) file_get_contents($root . '/assets/pdfjs/' . $file));
        }

        self::assertFileExists($root . '/assets/pdfjs/standard_fonts');
        self::assertFileExists($root . '/assets/pdfjs/cmaps');
        self::assertStringNotContainsString('http', str_replace(['Plugin::url('], '', $class));
    }
}
