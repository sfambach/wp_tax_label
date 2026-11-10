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

    public function testACodeTooSmallToScanIsAnError(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"qr","field":"code","x":0,"y":0,"w":6},{"type":"code128","field":"code","x":0,"y":8,"w":10,"h":8}]');
        [, $problems] = LabelDocument::svg(40, 30, $layout, ['code' => 'M-45704']);
        self::assertSame([Problem::TOO_SMALL, Problem::TOO_SMALL], array_map(static fn (Problem $p): string => $p->code, $problems));
        self::assertEqualsWithDelta(10.9, $problems[0]->details['needMm'], 0.05);

        $this->expectException(LabelProblems::class);
        LabelDocument::renderLayout(new LabelFormat('x', 40, 30, 40), $layout, [['code' => 'M-45704']]);
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
