<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * A label for one purpose: its size, how many to print, and the layout that fills it from a record (D-1153).
 *
 * ⚠️ *His word 2026-11-04: «size ist schon ein eigenes feld dann ersetzen wir Label durch zweck material, platz, versand» — the
 * name is not stored but is the purpose, with an optional addition where two templates share one; the size has its own column.*
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1153
 */
final class LabelTemplate
{
    /** Purpose ⇒ its word. */
    public const PURPOSES = ['platz' => 'Platz', 'kiste' => 'Kiste', 'material' => 'Material', 'einzelstueck' => 'Einzelstück', 'versand' => 'Versand'];

    /** The purpose a label of this code prefix starts with (D-1032 prefixes). */
    public const PURPOSE_OF_PREFIX = ['O' => 'platz', 'K' => 'kiste', 'M' => 'material', 'E' => 'einzelstueck'];

    public function __construct(
        public readonly string $key,
        public readonly string $purpose,
        public readonly LabelFormat $format,
        public readonly LabelLayout $layout,
        public readonly int $copies = 1,
        public readonly string $addition = '',
    ) {
        if (! array_key_exists($purpose, self::PURPOSES)) {
            throw new \InvalidArgumentException(sprintf('Unknown label purpose "%s".', $purpose));
        }
        if ($copies < 1) {
            throw new \InvalidArgumentException('A template prints at least one label.');
        }
        if (! $layout->fitsOn($format)) {
            throw new \InvalidArgumentException(sprintf('The layout of «%s» reaches past its label.', $key));
        }
    }

    public function name(): string
    {
        return trim(self::PURPOSES[$this->purpose] . ' ' . $this->addition);
    }

    /**
     * The labels shipped today, as layouts: the same boxes the fixed drawing used, so a print looks as before.
     * Fields: `code` (O-123, also the QR), `title`, `subtitle`.
     *
     * @return array<string, self>
     */
    public static function shipped(): array
    {
        $formats = LabelFormat::shipped();
        $wide    = static fn (float $w, float $h, float $qr): string => json_encode([
            ['type' => 'qr', 'field' => 'code', 'x' => 3, 'y' => 3, 'w' => $qr, 'h' => $qr, 'example' => 'M-12345'],
            ['type' => 'text', 'field' => 'title', 'x' => 6 + $qr, 'y' => 3, 'w' => $w - 9 - $qr, 'h' => min(22.0, $h * 0.4), 'pt' => 28, 'bold' => true, 'lines' => 2, 'example' => 'Widerstand 10 kΩ'],
            ['type' => 'text', 'field' => 'subtitle', 'x' => 6 + $qr, 'y' => 3 + min(22.0, $h * 0.4) + 1, 'w' => $w - 9 - $qr, 'h' => max(5.0, $h * 0.2), 'pt' => 14, 'example' => '0805 · 1 %'],
            ['type' => 'text', 'field' => 'code', 'x' => 6 + $qr, 'y' => $h - 4.5 - 5, 'w' => $w - 9 - $qr, 'h' => 5, 'pt' => 12, 'bottom' => true],
        ], JSON_THROW_ON_ERROR);
        $holder = json_encode([
            ['type' => 'text', 'field' => 'title', 'x' => 3, 'y' => 3, 'w' => 56, 'h' => 12, 'pt' => 28, 'bold' => true, 'lines' => 2, 'example' => 'Schublade 1'],
            ['type' => 'qr', 'field' => 'code', 'x' => 3, 'y' => 16, 'w' => 31, 'h' => 31, 'example' => 'O-12345'],
            ['type' => 'text', 'field' => 'subtitle', 'x' => 36, 'y' => 17, 'w' => 23, 'h' => 20, 'pt' => 12, 'lines' => 4, 'example' => 'A1-S1'],
            ['type' => 'text', 'field' => 'code', 'x' => 36, 'y' => 40.5, 'w' => 23, 'h' => 5, 'pt' => 12, 'bottom' => true],
        ], JSON_THROW_ON_ERROR);

        $templates = [
            new self('platz', 'platz', $formats['holder62'], LabelLayout::fromJson($holder)),
            new self('kiste', 'kiste', $formats['roll62'], LabelLayout::fromJson($wide(100, 62, 45)), 2),
            new self('material', 'material', $formats['material62'], LabelLayout::fromJson($wide(62, 30, 24))),
            new self('einzelstueck', 'einzelstueck', $formats['material62'], LabelLayout::fromJson($wide(62, 30, 24))),
            new self('versand', 'versand', $formats['roll102'], LabelLayout::fromJson($wide(102, 62, 45.9))),
        ];

        return array_combine(array_map(static fn (self $t): string => $t->key, $templates), $templates);
    }

    /** The shipped template of a fixed format — what a label built from a code and two lines is drawn with. */
    public static function forFormat(string $formatKey): self
    {
        foreach (self::shipped() as $template) {
            if ($template->format->key === $formatKey) {
                return $template;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown label format "%s".', $formatKey));
    }

    /** The values a layout reads from a label built the old way: code, title and second line. */
    public static function valuesOf(LabelContent $label): array
    {
        return ['code' => $label->code, 'title' => $label->title, 'subtitle' => $label->subtitle];
    }
}
