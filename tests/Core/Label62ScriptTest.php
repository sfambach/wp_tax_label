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

        foreach (['var TAPE_MM = 62;', 'var PRINT_MM = 58;', 'var PAGE_MM = 100;', 'var FREE_MM = PAGE_MM - 9;', 'var DPI = 300;', 'crop(canvas, Math.round(DPI / 25.4 * 12), cutMarks)', 'section{padding:4.5mm 2mm}'] as $line) {
            self::assertStringContainsString($line, $script);
        }
    }

    public function testPdfStaysInTheBrowserOnlyTheCroppedPictureGoesToThePrintService(): void
    {
        $script = self::script();

        // D-1507 had no request at all. D-1517 (Stefan 20.11. «aufs moderne Drucken umstellen»): one request, to the one endpoint,
        // carrying the cropped label as PNG — never the PDF (`source`, `file`) itself.
        self::assertSame(1, preg_match_all('/\bfetch\s*\(/', $script));
        self::assertStringContainsString('fetch(taxmodLabel62.service.url, ', $script);
        self::assertStringContainsString("label.toBlob(done, 'image/png')", $script);
        self::assertStringContainsString('png: png', $script);
        self::assertDoesNotMatchRegularExpression('/append\([^)]*(source|file)\b|XMLHttpRequest|sendBeacon/', $script);
        self::assertDoesNotMatchRegularExpression('#https?://#', $script);
        self::assertStringNotContainsString('print()', str_replace('view.print()', '', $script), 'Printing happens only in print(), after a click.');
    }

    public function testWithoutWorkplacePrinterTheBrowserPrints(): void
    {
        $script = self::script();

        // Prüftor D-1517 (3): the browser's dialog is the fallback — no default printer, a refusal, a failed job.
        self::assertStringContainsString("return state === 'sent' ? true : print(pages, caption).then(", $script);
        self::assertStringContainsString("Promise.resolve('browser')", $script);
        // Stefan 20.11. 18:28: never sent without the dialog; a cancelled dialog prints nothing.
        self::assertStringContainsString('? confirmed(pages, caption) : ', $script);
        self::assertSame(1, substr_count($script, 'toService(pages, caption, printer).then(done)'), 'Only «Drucken» in the dialog sends.');
        self::assertStringContainsString("if (state === 'cancelled') {\n\t\t\t\t\treturn false;", $script);
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
        self::assertStringContainsString(': print(pages, caption).then(function () { return true; })', $script);
        self::assertMatchesRegularExpression('/view\.print\(\);\s*done\(\);/', $script);
    }

    public function testCutMarksAreLeftOutOnlyWhenAsked(): void
    {
        $script = self::script();

        // Stefan 20.11. «Etwas klein»: the Post stamp's corner crosses kept it small; eBay's DHL label does not ask.
        self::assertStringContainsString('if (cutMarks) {', $script);
        self::assertStringContainsString("hasAttribute('data-taxmod-label62-cut-marks')", $script);
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
