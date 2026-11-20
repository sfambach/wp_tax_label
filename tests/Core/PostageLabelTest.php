<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\LabelPrinter;
use TaxmodLabel\Core\Pdf\PostageLabel;

/** D-1517: the cropped postage picture becomes one 100 mm label across the printer's roll, as large as the browser printed it. */
final class PostageLabelTest extends TestCase
{
    public function testLabelKeepsTheBrowsersPage(): void
    {
        ['pdf' => $pdf, 'format' => $format] = PostageLabel::pdf(PostageLabel::picture(self::png(290, 500)), self::brother());

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame(62.0, $format->widthMm);
        self::assertSame(62.0, $format->rollMm);
        self::assertSame(100.0, $format->heightMm, 'The page the browser printed on: the label as large as before.');
        self::assertTrue($format->turnedOnRoll(), 'As wide as the roll: turned like the 62 mm labels.');
    }

    public function testPrinterThatTakesLabelsAsTheyAreIsNotTurned(): void
    {
        $format = PostageLabel::pdf(PostageLabel::picture(self::png(200, 300)), new LabelPrinter('phomemo', [], false, 'Phomemo', 0, 0, 50))['format'];

        self::assertFalse($format->turnedOnRoll());
        self::assertSame(50.0, $format->widthMm);
    }

    public function testOnlyAPng(): void
    {
        $this->expectException(LabelError::class);
        PostageLabel::picture('%PDF-1.4');
    }

    public function testNotLargerThanFiveMegabytes(): void
    {
        $this->expectException(LabelError::class);
        PostageLabel::picture(str_repeat('x', PostageLabel::MAX_BYTES + 1));
    }

    public function testATinyPictureIsNoLabel(): void
    {
        $this->expectException(LabelError::class);
        PostageLabel::pdf(PostageLabel::picture(self::png(20, 40)), self::brother());
    }

    public function testAStripIsNoLabel(): void
    {
        $this->expectException(LabelError::class);
        PostageLabel::pdf(PostageLabel::picture(self::png(100, 1000)), self::brother());
    }

    public function testEndpointChecksBeforeItActs(): void
    {
        $code   = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/PostagePrint.php');
        $handle = substr($code, (int) strpos($code, 'public static function handle'), (int) strpos($code, 'private static function allowed') - (int) strpos($code, 'public static function handle'));
        $order  = array_map(static fn (string $needle): int => (int) strpos($handle, $needle), ['self::allowed()', 'is_uploaded_file(', 'PostageLabel::picture(', 'self::printer($wanted)', 'PostageLabel::pdf(', '->queuePrint(']);
        $sorted = $order;
        sort($sorted);

        self::assertNotContains(0, array_slice($order, 1));
        self::assertSame($sorted, $order, 'CD-5: capability → nonce → validate → act.');
        self::assertMatchesRegularExpression('/function allowed\(\): void\s*\{\s*if \(! current_user_can\(.*?check_ajax_referer\(/s', $code);
        self::assertStringContainsString("add_action('wp_ajax_' . self::ACTION", $code, 'Only on a request from a click, never on a hook of its own.');
        self::assertStringContainsString('isset($printers[$wanted])', $code, 'Only a printer the dialog offers.');
    }

    public function testPrintDialogIsTheModulesOwn(): void
    {
        $root   = dirname(__DIR__, 2);
        $code   = (string) file_get_contents($root . '/src/WordPress/PostagePrint.php');
        $button = (string) file_get_contents($root . '/src/WordPress/LabelButton.php');
        $dialog = substr($code, (int) strpos($code, 'public static function dialog'), (int) strpos($code, 'public static function handle') - (int) strpos($code, 'public static function dialog'));

        // Stefan 20.11. 18:28 «Druckdialog … der Drucker … mit der Voransicht»; Prüftor: no second dialog (D-1511 rule 3) —
        // LabelButton's dialog with the picture as one more source of its preview, its printer field, no select of its own.
        self::assertStringStartsWith('public static function dialog(): void' . "\n    {\n        self::allowed();", $dialog);
        self::assertStringContainsString('LabelButton::postageDialog($printers)', $dialog);
        self::assertDoesNotMatchRegularExpression('/SelectMarkup|<select|DialogMarkup/', $code);
        self::assertSame(2, substr_count($button, 'self::printerField('), 'One printer field: the template dialog and the postage dialog.');
        self::assertSame(2, substr_count($button, 'self::panel('), 'One layout: options left, preview right.');
        self::assertStringContainsString('self::previewBox(true)', $button);
        self::assertStringContainsString('dialogCatalog()->printerList()', $code, 'D-1502: the workplace\'s printers, its default first.');
    }

    private static function brother(): LabelPrinter
    {
        return new LabelPrinter('QL-810W', [], true, 'Brother', 0.0, 0.0, 62.0);
    }

    private static function png(int $width, int $height): string
    {
        $raw = str_repeat("\0" . str_repeat("\xff", $width), $height);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
    }
}
