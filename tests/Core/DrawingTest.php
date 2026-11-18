<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\Drawing\Picture;
use TaxmodLabel\Core\Drawing\Resolution;
use TaxmodLabel\Core\LabelContent;
use TaxmodLabel\Core\LabelDocument;
use TaxmodLabel\Core\LabelFormat;
use TaxmodLabel\Core\LabelLayout;
use TaxmodLabel\Core\LabelProblems;
use TaxmodLabel\Core\Problem;

final class DrawingTest extends TestCase
{
    /** A 2 × 1 PNG, red and half-transparent blue. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAEUlEQVR4nGP4z8Dwn4HhfwMAD3oDfgvLLrEAAAAASUVORK5CYII=';

    public function testResolutionSnapsModulesToWholeDots(): void
    {
        $r = new Resolution(203.0);
        self::assertEqualsWithDelta(3 / (203 / 25.4), $r->module(0.4, 3, 0.33), 1e-9, '0.4 mm is 3.2 dots → 3 dots');
        self::assertSame(0.0, $r->module(0.3, 3, 0.33), 'under 3 dots: refused');
        self::assertEqualsWithDelta(5 / (300 / 25.4), (new Resolution(300.0))->minimumMm(3, 0.35), 1e-9, 'at 300 dpi 0.35 mm needs 5 dots');
    }

    public function testACodeUnderTheRecommendedSizeIsAWarningAndStillDrawn(): void
    {
        // D-1443: under the minimum it is drawn at the whole dots that fit, with a warning; refused only where not one dot fits.
        $layout = LabelLayout::fromJson('[{"type":"qr","field":"code","x":0,"y":0,"w":6},{"type":"code128","field":"code","x":0,"y":8,"w":10,"h":8}]');
        [, $problems] = LabelDocument::svg(40, 30, $layout, ['code' => 'M-45704']);
        self::assertSame([[Problem::TOO_SMALL, false], [Problem::TOO_SMALL, true]], array_map(static fn (Problem $p): array => [$p->code, $p->error], $problems));
        self::assertEqualsWithDelta(10.9, $problems[0]->details['needMm'], 0.05);

        $this->expectException(LabelProblems::class);
        LabelDocument::renderLayout(new LabelFormat('x', 40, 30, 40), $layout, [['code' => 'M-45704']]);
    }

    /** D-1443: the X1 labels 50576 (QR 10 mm) and 61868 (QR 8 mm) as they stand live — printed at 203 dpi, with a warning. */
    public function testTheX1LabelsPrintWithAWarning(): void
    {
        $cases = [
            '50576' => [27, 12, '[{"type": "qr", "field": "code", "x": 2, "y": 1, "w": 10, "h": 10, "example": "M-12345"}, {"type": "text", "field": "node", "x": 17, "y": 0.3, "w": 10, "h": 3, "pt": 7, "center": true, "example": "Widerstand"}, {"type": "text", "field": "Widerstandswert|Kapazität|Induktivität|Farbe|Typ", "x": 17, "y": 3.4, "w": 10, "h": 4.6, "pt": 11, "short": true, "center": true, "example": "24k"}, {"type": "text", "field": "Bauform", "x": 17, "y": 8.2, "w": 10, "h": 3.5, "pt": 9, "center": true, "example": "0603"}]'],
            '61868' => [40, 12, '[{"type": "text", "field": "Widerstandswert|Kapazität|Induktivität|Farbe|Typ", "x": 2.5, "y": 0.8, "w": 9, "h": 6.4, "pt": 13, "example": "30k", "short": true}, {"type": "text", "field": "Bauform", "x": 2.5, "y": 7.6, "w": 9, "h": 4, "pt": 10, "example": "1206"}, {"type": "line", "x": 12, "y": 0, "w": 0.2, "h": 12}, {"type": "qr", "field": "code", "x": 18.5, "y": 2, "w": 8, "h": 8, "example": "M-12345"}, {"type": "text", "field": "Widerstandswert|Kapazität|Induktivität|Farbe|Typ", "x": 30.5, "y": 0.8, "w": 9, "h": 6.4, "pt": 13, "example": "30k", "short": true, "right": true}, {"type": "text", "field": "Bauform", "x": 30.5, "y": 7.6, "w": 9, "h": 4, "pt": 10, "example": "1206", "right": true}]'],
        ];
        foreach ($cases as $id => [$w, $h, $json]) {
            $layout = LabelLayout::fromJson($json);
            $values = $layout->examples() + ['code' => 'M-12345'];
            [, $problems] = LabelDocument::svg($w, $h, $layout, $values, new Resolution(203.0));
            self::assertFalse(Problem::hasError($problems), $id . ': no refusal');
            self::assertContains(Problem::TOO_SMALL, array_map(static fn (Problem $p): string => $p->code, $problems), $id . ': the warning stands');
            $pdf = LabelDocument::renderLayout(new LabelFormat('x' . $id, $w, $h, 12), $layout, [$values], new Resolution(203.0));
            self::assertStringStartsWith('%PDF', $pdf, $id . ': it prints');
        }
    }

    public function testABadValueIsAnError(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"ean13","field":"code","x":0,"y":0,"w":50,"h":15}]');
        [, $problems] = LabelDocument::svg(60, 20, $layout, ['code' => 'M-1']);
        self::assertSame(Problem::BAD_VALUE, $problems[0]->code);
        [, $none] = LabelDocument::svg(60, 20, $layout, ['code' => '']);
        self::assertSame([], $none, 'an empty value leaves the code out, as before');
    }

    public function testGrowingTextThatLeavesTheLabelIsAnError(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"text","text":"ein langer Text, der umbricht und wächst","x":0,"y":0,"w":10,"h":3,"pt":10,"fit":"grow-down"},{"type":"text","text":"breit","x":0,"y":20,"w":2,"h":5,"pt":10,"fit":"grow-right"}]');
        [, $problems] = LabelDocument::svg(20, 25, $layout, []);
        self::assertSame([Problem::GREW_OUTSIDE], array_values(array_unique(array_map(static fn (Problem $p): string => $p->code, $problems))));
        [, $fine] = LabelDocument::svg(100, 100, $layout, []);
        self::assertSame([], $fine);
    }

    public function testShrinkingIsTheDefaultAndStaysInItsBox(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"text","text":"Widerstand","x":0,"y":0,"w":15,"h":5,"pt":28}]');
        [$svg, $problems] = LabelDocument::svg(20, 10, $layout, []);
        self::assertSame([], $problems);
        preg_match('/font-size="([\d.]+)"/', $svg, $m);
        self::assertLessThan(5.0, (float) $m[1], 'shrunk below 28 pt to fit 15 mm');
    }

    public function testPicturesInPdfAndSvg(): void
    {
        $picture = Picture::fromBytes((string) base64_decode(self::PNG));
        self::assertSame(2, $picture->widthPx);
        self::assertSame("\xFF\x00\x00\x7F\x7F\xFF", gzuncompress($picture->forPdf()["data"]), 'blue at half alpha laid on white');

        $layout = LabelLayout::fromJson('[{"type":"image","media":"media:5","x":0,"y":0,"w":10,"h":10}]');
        $pdf    = LabelDocument::renderLayout(new LabelFormat('x', 20, 20, 62), $layout, [[]], null, [5 => $picture]);
        self::assertStringContainsString('/Subtype /Image', $pdf);
        [$svg] = LabelDocument::svg(20, 20, $layout, [], null, [5 => $picture]);
        self::assertStringContainsString('data:image/png;base64,', $svg);

        [, $missing] = LabelDocument::svg(20, 20, $layout, []);
        self::assertSame(Problem::NO_PICTURE, $missing[0]->code);
    }

    public function testCopiesShareOneDrawingAndOnePicture(): void
    {
        // Review L1: 50 copies were 50 drawings and 50 embedded pictures.
        $picture = Picture::fromBytes((string) base64_decode(self::PNG));
        $layout  = LabelLayout::fromJson('[{"type":"qr","field":"code","x":1,"y":1,"w":14},{"type":"text","field":"title","x":16,"y":2,"w":20,"h":5,"pt":10},{"type":"image","media":"media:5","x":40,"y":2,"w":10,"h":10}]');
        $format  = new LabelFormat('x', 62, 20, 62);
        $one     = LabelDocument::renderLayout($format, $layout, [['code' => 'O-1', 'title' => 'Box']], null, [5 => $picture]);
        $fifty   = LabelDocument::renderLayout($format, $layout, array_fill(0, 50, ['code' => 'O-1', 'title' => 'Box']), null, [5 => $picture]);

        self::assertSame(50, substr_count($fifty, '/Type /Page '), 'still one page per copy');
        self::assertSame(1, substr_count($fifty, '/Subtype /Image'));
        self::assertSame(1, substr_count($fifty, '(Box) Tj'));
        self::assertLessThan(strlen($one) + 50 * 400, strlen($fifty), "each further copy costs a page object, not a drawing");

        $mixed = LabelDocument::renderLayout($format, $layout, [['code' => 'O-1', 'title' => 'A'], ['code' => 'O-2', 'title' => 'B'], ['code' => 'O-1', 'title' => 'A']], null, [5 => $picture]);
        self::assertSame(3, substr_count($mixed, '/Type /Page '));
        self::assertSame(1, substr_count($mixed, '(A) Tj'), 'a repeated label is drawn once, wherever it stands');
        self::assertSame(1, substr_count($mixed, '(B) Tj'));
        self::assertSame(1, substr_count($mixed, '/Subtype /Image'), 'one picture for different labels too');
    }

    public function testTheFixedFormatsStillPrint(): void
    {
        foreach (LabelFormat::shipped() as $format) {
            self::assertStringStartsWith('%PDF-1.4', LabelDocument::render($format, [LabelContent::sample()]));
        }
    }

    public function testFrameIsFourBars(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"rect","x":1,"y":1,"w":10,"h":5,"stroke":0.5}]');
        [$svg] = LabelDocument::svg(20, 10, $layout, []);
        self::assertSame(4, substr_count($svg, 'z'));
    }
}
