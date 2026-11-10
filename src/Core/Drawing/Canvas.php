<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Drawing;

/**
 * Where a label is drawn: the PDF that is printed, or the SVG the editor shows — one drawing, two surfaces.
 *
 * Millimetres, origin at the top left corner of the label.
 *
 * @see docs/konzept.md §5
 */
interface Canvas
{
    /** @param list<array{0: float, 1: float, 2: float, 3: float}> $rectangles x, y, width, height, filled black */
    public function rectangles(array $rectangles): void;

    public function text(float $xMm, float $baselineMm, string $text, float $sizePt, bool $bold = false): void;

    public function picture(float $xMm, float $yMm, float $widthMm, float $heightMm, Picture $picture): void;
}
