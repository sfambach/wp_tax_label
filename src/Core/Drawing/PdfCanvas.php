<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Drawing;

use TaxmodLabel\Core\Pdf\PdfDocument;

/**
 * The canvas a label is printed from: the current page of a PDF.
 *
 * @see docs/konzept.md §5
 */
final class PdfCanvas implements Canvas
{
    public function __construct(private readonly PdfDocument $pdf)
    {
    }

    public function rectangles(array $rectangles): void
    {
        $this->pdf->rectangles($rectangles);
    }

    public function text(float $xMm, float $baselineMm, string $text, float $sizePt, bool $bold = false): void
    {
        $this->pdf->text($xMm, $baselineMm, $text, $sizePt, $bold);
    }

    public function picture(float $xMm, float $yMm, float $widthMm, float $heightMm, Picture $picture): void
    {
        $this->pdf->image($xMm, $yMm, $widthMm, $heightMm, $picture->forPdf(), $picture->widthPx, $picture->heightPx);
    }
}
