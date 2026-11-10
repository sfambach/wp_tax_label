<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * One thing on a label — a text, a code, a picture, a line or a frame — in a box measured in mm from the top left corner.
 *
 * @see docs/konzept.md §4
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1153, D-1237
 */
final class LabelElement
{
    public const TEXT       = 'text';
    public const QR         = 'qr';
    public const DATAMATRIX = 'datamatrix';
    public const CODE128    = 'code128';
    public const EAN13      = 'ean13';
    public const CODE39     = 'code39';
    public const IMAGE      = 'image';
    /** D-1237: a black bar over its box and nothing else — a cut mark, a horizontal or vertical line. */
    public const LINE = 'line';
    /** Stefan 2026-11-10: «Rahmen, also so ein Rechteck zeichnen». */
    public const RECT = 'rect';

    public const TYPES       = [self::TEXT, self::QR, self::DATAMATRIX, self::CODE128, self::EAN13, self::CODE39, self::IMAGE, self::LINE, self::RECT];
    public const MATRIX_CODES = [self::QR, self::DATAMATRIX];
    public const BAR_CODES   = [self::CODE128, self::EAN13, self::CODE39];

    /** The text shrinks until it fits its box — the default (Stefan 2026-11-10). */
    public const FIT_SHRINK = 'shrink';
    /** The type stays at its size, the text wraps and the box grows down. */
    public const FIT_DOWN = 'grow-down';
    /** The type stays at its size, one line, the box grows to the right. */
    public const FIT_RIGHT = 'grow-right';
    public const FITS      = [self::FIT_SHRINK, self::FIT_DOWN, self::FIT_RIGHT];

    public function __construct(
        public readonly string $type,
        public readonly float $xMm,
        public readonly float $yMm,
        public readonly float $widthMm,
        public readonly float $heightMm,
        /** The record field whose value is shown; «a|b» takes the first one with a value (D-1217). */
        public readonly string $field = '',
        /** A fixed text instead of a field — data of the label, not a software string (AR-2). */
        public readonly string $text = '',
        /** The largest size a text may take; with a growing box, its size. */
        public readonly float $maxPt = 12.0,
        public readonly bool $bold = false,
        public readonly int $lines = 1,
        /** A text sits at the top of its box, or with its last line on the bottom edge. */
        public readonly bool $atBottom = false,
        /** D-1154: what the preview and a test print show where no record fills the field. */
        public readonly string $example = '',
        /** D-1217, D-1236: a component value in its short code («24k», «4n7»). */
        public readonly bool $short = false,
        public readonly bool $centred = false,
        public readonly bool $flushRight = false,
        public readonly string $fit = self::FIT_SHRINK,
        /** A frame's line width. */
        public readonly float $strokeMm = 0.3,
        /** A bar code's digits printed under the bars. */
        public readonly bool $human = true,
        /** The picture's attachment id in the media library (Stefan 2026-11-10). */
        public readonly int $media = 0,
        /** A Data Matrix code may take a rectangular size where that fits its box better. */
        public readonly bool $anyShape = false,
    ) {
    }

    public function isText(): bool
    {
        return $this->type === self::TEXT;
    }

    public function isCode(): bool
    {
        return in_array($this->type, self::MATRIX_CODES, true) || in_array($this->type, self::BAR_CODES, true);
    }

    /** Whether the element shows a value at all — a line, a frame and a picture do not. */
    public function readsValue(): bool
    {
        return $this->isText() || $this->isCode();
    }

    /**
     * D-1217: «Widerstandswert|Kapazität» — the fields to try, in order.
     *
     * @return list<string>
     */
    public function alternatives(): array
    {
        if (! $this->readsValue() || $this->field === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $this->field)), static fn (string $f): bool => $f !== ''));
    }

    /**
     * What the element shows: its fixed text, else the first field with a value.
     *
     * @param array<string, string> $values
     */
    public function valueFrom(array $values): string
    {
        if ($this->text !== '') {
            return $this->text;
        }
        foreach ($this->alternatives() as $field) {
            $value = trim((string) ($values[$field] ?? ''));
            if ($value !== '') {
                return $this->short ? ShortValue::of($value) : $value;
            }
        }

        return '';
    }

    /** @return array<string, string|float|int|bool> only what differs from the defaults, so the JSON stays short */
    public function toArray(): array
    {
        return array_filter([
            'type'    => $this->type,
            'field'   => $this->field === '' ? null : $this->field,
            'text'    => $this->text === '' ? null : $this->text,
            'x'       => $this->xMm,
            'y'       => $this->yMm,
            'w'       => $this->widthMm,
            'h'       => $this->heightMm,
            'pt'      => $this->isText() ? $this->maxPt : null,
            'bold'    => $this->bold ?: null,
            'lines'   => $this->lines > 1 ? $this->lines : null,
            'bottom'  => $this->atBottom ?: null,
            'example' => $this->example === '' ? null : $this->example,
            'short'   => $this->short ?: null,
            'center'  => $this->centred ?: null,
            'right'   => $this->flushRight ?: null,
            'fit'     => $this->isText() && $this->fit !== self::FIT_SHRINK ? $this->fit : null,
            'stroke'  => $this->type === self::RECT && abs($this->strokeMm - 0.3) > 0.001 ? $this->strokeMm : null,
            'human'   => in_array($this->type, self::BAR_CODES, true) && ! $this->human ? false : null,
            'media'   => $this->type === self::IMAGE && $this->media > 0 ? 'media:' . $this->media : null,
            'shape'   => $this->type === self::DATAMATRIX && $this->anyShape ? 'any' : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
