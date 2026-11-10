<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Drawing;

use TaxmodLabel\Core\Pdf\PdfDocument;

/**
 * The canvas the editor shows: an SVG in millimetres, drawn by the same code as the PDF so the preview is what prints.
 *
 * @see docs/konzept.md §5
 */
final class SvgCanvas implements Canvas
{
    private string $body = '';

    public function __construct(private readonly float $widthMm, private readonly float $heightMm)
    {
    }

    public function rectangles(array $rectangles): void
    {
        $path = '';
        foreach ($rectangles as [$x, $y, $w, $h]) {
            $path .= sprintf('M%s %sh%sv%sh-%sz', self::n($x), self::n($y), self::n($w), self::n($h), self::n($w));
        }
        if ($path !== '') {
            $this->body .= '<path d="' . $path . '" fill="#000" shape-rendering="crispEdges"/>';
        }
    }

    public function text(float $xMm, float $baselineMm, string $text, float $sizePt, bool $bold = false): void
    {
        $this->body .= sprintf(
            '<text x="%s" y="%s" font-size="%s" font-family="Helvetica, Arial, sans-serif"%s xml:space="preserve">%s</text>',
            self::n($xMm),
            self::n($baselineMm),
            self::n(PdfDocument::pointsToMm($sizePt)),
            $bold ? ' font-weight="bold"' : '',
            htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        );
    }

    public function picture(float $xMm, float $yMm, float $widthMm, float $heightMm, Picture $picture): void
    {
        $this->body .= sprintf(
            '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="none" href="%s"/>',
            self::n($xMm),
            self::n($yMm),
            self::n($widthMm),
            self::n($heightMm),
            htmlspecialchars($picture->dataUri(), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        );
    }

    public function render(): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" width="%1$smm" height="%2$smm"><rect width="%1$s" height="%2$s" fill="#fff"/>%3$s</svg>',
            self::n($this->widthMm),
            self::n($this->heightMm),
            $this->body,
        );
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }
}
