<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodLabel\Core\LabelElement;
use TaxmodLabel\Core\LabelLayout;
use TaxmodLabel\Core\LabelProblems;
use TaxmodLabel\Core\Problem;

final class LayoutTest extends TestCase
{
    public function testTheStoredListStillReads(): void
    {
        $layout = LabelLayout::fromJson('[{"typ":"text","feld":"title","x":1,"y":1,"b":20,"h":5,"fett":true},{"type":"qr","field":"code","x":30,"y":1,"w":10}]');
        self::assertCount(2, $layout->elements);
        self::assertTrue($layout->elements[0]->bold);
        self::assertSame(10.0, $layout->elements[1]->heightMm, 'a QR box without height is square');
        self::assertNull($layout->settings);
    }

    public function testTheEditorDocumentCarriesTheSettings(): void
    {
        [$layout, $problems] = LabelLayout::parse('{"label":{"w":62,"h":30,"roll":62,"copies":2},"elements":[{"type":"rect","x":0,"y":0,"w":62,"h":30}]}');
        self::assertSame([], $problems);
        self::assertSame(62.0, $layout->settings->widthMm);
        self::assertSame(2, $layout->settings->copies);
        self::assertStringNotContainsString('label', $layout->toJson(), 'the stored list holds only the elements');
        self::assertStringContainsString('"copies": 2', $layout->toDocument());
    }

    public function testEveryProblemIsNamedWithItsElement(): void
    {
        [$layout, $problems] = LabelLayout::parse('[{"type":"text","x":1,"y":1,"w":5,"h":5},{"type":"star","x":0,"y":0,"w":1,"h":1},{"type":"image","x":0,"y":0,"w":5,"h":5},{"type":"qr","field":"c","x":0,"y":0,"w":0}]');
        $codes = array_map(static fn (Problem $p): string => $p->code . '@' . $p->element, $problems);
        self::assertSame(['needs_field@1', 'unknown_type@2', 'needs_media@3', 'needs_box@4'], $codes);
        self::assertSame([], $layout->elements);
    }

    public function testBrokenJson(): void
    {
        [$layout, $problems] = LabelLayout::parse('[{"type":"text",}]');
        self::assertNull($layout);
        self::assertSame(Problem::JSON, $problems[0]->code);
        $this->expectException(LabelProblems::class);
        LabelLayout::fromJson('{');
    }

    public function testOutside(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"line","x":50,"y":0,"w":20,"h":0.3}]');
        self::assertSame(Problem::OUTSIDE, $layout->outside(62, 30)[0]->code);
        self::assertSame([], $layout->outside(70, 30));
    }

    public function testRoundTripKeepsWhatDiffersFromTheDefaults(): void
    {
        $json   = '[{"type":"text","field":"a|b","x":1,"y":2,"w":3,"h":4,"pt":9,"fit":"grow-right"},{"type":"datamatrix","field":"code","x":0,"y":0,"w":5,"h":5,"shape":"any"},{"type":"code128","field":"code","x":0,"y":0,"w":40,"h":10,"human":false},{"type":"image","media":"media:12","x":0,"y":0,"w":5,"h":5},{"type":"rect","x":0,"y":0,"w":5,"h":5,"stroke":0.5}]';
        $again  = LabelLayout::fromJson(LabelLayout::fromJson($json)->toJson());
        self::assertSame(LabelElement::FIT_RIGHT, $again->elements[0]->fit);
        self::assertTrue($again->elements[1]->anyShape);
        self::assertFalse($again->elements[2]->human);
        self::assertSame(12, $again->elements[3]->media);
        self::assertSame(0.5, $again->elements[4]->strokeMm);
        self::assertSame(['a', 'b', 'code'], $again->fields());
        self::assertSame([12], $again->media());
    }

    public function testFixedTextAndAlternatives(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"text","text":"Lager","x":0,"y":0,"w":5,"h":5},{"type":"text","field":"Widerstandswert|Kapazität","short":true,"x":0,"y":0,"w":5,"h":5}]');
        self::assertSame('Lager', $layout->elements[0]->valueFrom([]));
        self::assertSame('4n7', $layout->elements[1]->valueFrom(['Kapazität' => '4,7 nF']));
    }
}
