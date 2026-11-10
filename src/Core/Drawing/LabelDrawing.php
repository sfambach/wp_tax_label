<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Drawing;

use TaxmodLabel\Core\Code\Bars;
use TaxmodLabel\Core\Code\Code128;
use TaxmodLabel\Core\Code\Code39;
use TaxmodLabel\Core\Code\DataMatrix;
use TaxmodLabel\Core\Code\Ean13;
use TaxmodLabel\Core\Code\Matrix;
use TaxmodLabel\Core\Code\QrCode;
use TaxmodLabel\Core\LabelElement;
use TaxmodLabel\Core\LabelLayout;
use TaxmodLabel\Core\Pdf\PdfDocument;
use TaxmodLabel\Core\Problem;

/**
 * Draws the elements of a layout, filled with one record's values, onto a canvas — and says what it could not draw well.
 *
 * ```mermaid
 * flowchart TB
 *   E["element"] --> T{"type"}
 *   T -- text --> F["TextFitter: shrink / grow"]
 *   T -- "qr · datamatrix" --> M["Matrix → modules at whole dots"]
 *   T -- "code128 · ean13 · code39" --> B["Bars → bars at whole dots, text below"]
 *   T -- image --> P["Picture, aspect kept"]
 *   T -- "line · rect" --> R["rectangles"]
 *   F & M & B & P & R --> C["Canvas (PDF or SVG)"]
 * ```
 *
 * @see docs/konzept.md §4, §5
 */
final class LabelDrawing
{
    /** Bars lower than this are hard to hit with a hand scanner (assumed, docs/konzept.md §10). */
    public const MIN_BAR_HEIGHT_MM = 4.0;

    /**
     * @param array<string, string> $values  field ⇒ text
     * @param array<int, Picture>   $pictures media id ⇒ picture
     * @return list<Problem>
     */
    public static function draw(Canvas $canvas, LabelLayout $layout, array $values, float $widthMm, float $heightMm, Resolution $resolution, array $pictures = []): array
    {
        $problems = [];
        foreach ($layout->elements as $i => $element) {
            $number = $i + 1;
            $found  = match (true) {
                $element->type === LabelElement::LINE => self::line($canvas, $element),
                $element->type === LabelElement::RECT => self::frame($canvas, $element),
                $element->type === LabelElement::IMAGE => self::picture($canvas, $element, $pictures, $number),
                $element->isText() => self::text($canvas, $element, $element->valueFrom($values), $widthMm, $heightMm, $number),
                default => self::code($canvas, $element, $element->valueFrom($values), $resolution, $number),
            };
            $problems = [...$problems, ...$found];
        }

        return $problems;
    }

    /** @return list<Problem> */
    private static function line(Canvas $canvas, LabelElement $e): array
    {
        $canvas->rectangles([[$e->xMm, $e->yMm, $e->widthMm, $e->heightMm]]);

        return [];
    }

    /** @return list<Problem> */
    private static function frame(Canvas $canvas, LabelElement $e): array
    {
        $s = min($e->strokeMm, $e->widthMm / 2, $e->heightMm / 2);
        $canvas->rectangles([
            [$e->xMm, $e->yMm, $e->widthMm, $s],
            [$e->xMm, $e->yMm + $e->heightMm - $s, $e->widthMm, $s],
            [$e->xMm, $e->yMm + $s, $s, $e->heightMm - 2 * $s],
            [$e->xMm + $e->widthMm - $s, $e->yMm + $s, $s, $e->heightMm - 2 * $s],
        ]);

        return [];
    }

    /**
     * @param array<int, Picture> $pictures
     * @return list<Problem>
     */
    private static function picture(Canvas $canvas, LabelElement $e, array $pictures, int $number): array
    {
        $picture = $pictures[$e->media] ?? null;
        if ($picture === null) {
            return [new Problem(Problem::NO_PICTURE, $number, ['media' => $e->media])];
        }
        $scale = min($e->widthMm / $picture->widthPx, $e->heightMm / $picture->heightPx);
        $w     = $picture->widthPx * $scale;
        $h     = $picture->heightPx * $scale;
        $canvas->picture($e->xMm + ($e->widthMm - $w) / 2, $e->yMm + ($e->heightMm - $h) / 2, $w, $h, $picture);

        return [];
    }

    /** @return list<Problem> */
    private static function text(Canvas $canvas, LabelElement $box, string $text, float $labelW, float $labelH, int $number): array
    {
        if ($text === '') {
            return [];
        }
        $problems = [];
        $width    = $box->widthMm;
        $height   = $box->heightMm;

        if ($box->fit === LabelElement::FIT_DOWN) {
            $size   = $box->maxPt;
            $lines  = TextFitter::lines($text, $width, $size, $box->bold);
            $height = max($height, TextFitter::heightMm(count($lines), $size) + PdfDocument::pointsToMm($size) * 0.25);
        } elseif ($box->fit === LabelElement::FIT_RIGHT) {
            $size  = $box->maxPt;
            $lines = [$text];
            $width = max($width, PdfDocument::textWidthMm($text, $size, $box->bold));
        } else {
            $maxPt = $box->maxPt;
            do {
                [$lines, $size] = $box->lines > 1 ? TextFitter::wrap($text, $width, $maxPt, $box->bold, $box->lines) : (static function () use ($text, $width, $maxPt, $box): array {
                    [$line, $size] = TextFitter::fit($text, $width, $maxPt, $box->bold);

                    return [[$line], $size];
                })();
                $maxPt = $size - 0.5;
            } while (TextFitter::heightMm(count($lines), $size) > $height && $maxPt >= TextFitter::MIN_PT);
            if (str_ends_with(end($lines) ?: '', '…') && ! str_ends_with($text, '…')) {
                $problems[] = Problem::warning(Problem::CUT, $number);
            }
        }

        if ($box->xMm + $width > $labelW + 0.01 || $box->yMm + $height > $labelH + 0.01) {
            $problems[] = new Problem(Problem::GREW_OUTSIDE, $number);
        }

        $lineMm = PdfDocument::pointsToMm($size);
        $first  = $box->atBottom ? $box->yMm + $height - (count($lines) - 1) * $lineMm * TextFitter::LEADING : $box->yMm + $lineMm;
        foreach ($lines as $i => $line) {
            $room = max(0.0, $width - PdfDocument::textWidthMm($line, $size, $box->bold));
            $x    = $box->xMm + ($box->flushRight ? $room : ($box->centred ? $room / 2 : 0.0));
            $canvas->text($x, $first + $i * $lineMm * TextFitter::LEADING, $line, $size, $box->bold);
        }

        return $problems;
    }

    /** @return list<Problem> */
    private static function code(Canvas $canvas, LabelElement $e, string $value, Resolution $resolution, int $number): array
    {
        if ($value === '') {
            return [];
        }
        try {
            return match ($e->type) {
                LabelElement::QR         => self::matrix($canvas, $e, [QrCode::encode($value)], $resolution, $number),
                LabelElement::DATAMATRIX => self::matrix($canvas, $e, DataMatrix::candidates($value, $e->anyShape), $resolution, $number),
                LabelElement::CODE128    => self::bars($canvas, $e, Code128::encode($value), $resolution, $number),
                LabelElement::EAN13      => self::bars($canvas, $e, Ean13::encode($value), $resolution, $number),
                default                  => self::bars($canvas, $e, Code39::encode($value), $resolution, $number),
            };
        } catch (\InvalidArgumentException $ex) {
            return [new Problem(Problem::BAD_VALUE, $number, ['type' => $e->type, 'value' => $value, 'message' => $ex->getMessage()])];
        }
    }

    /**
     * The symbol of the candidates whose modules come out largest in the box, drawn at whole dots.
     *
     * @param list<Matrix> $candidates
     * @return list<Problem>
     */
    private static function matrix(Canvas $canvas, LabelElement $e, array $candidates, Resolution $resolution, int $number): array
    {
        $best   = null;
        $module = 0.0;
        foreach ($candidates as $candidate) {
            $q      = $candidate->quietModules();
            $wanted = min($e->widthMm / ($candidate->width() + 2 * $q), $e->heightMm / ($candidate->height() + 2 * $q));
            $snapped = $resolution->module($wanted, Resolution::MATRIX_MIN_DOTS, Resolution::MATRIX_MIN_MM);
            if ($best === null || $snapped > $module) {
                [$best, $module] = [$candidate, $snapped];
            }
        }
        if ($module <= 0.0) {
            $q = $best->quietModules();
            $min = $resolution->minimumMm(Resolution::MATRIX_MIN_DOTS, Resolution::MATRIX_MIN_MM);

            return [new Problem(Problem::TOO_SMALL, $number, ['needMm' => round(max($best->width(), $best->height()) * $min + 2 * $q * $min, 1), 'dpi' => $resolution->dpi])];
        }

        $left       = $resolution->snap($e->xMm + $best->quietModules() * $module);
        $top        = $resolution->snap($e->yMm + $best->quietModules() * $module);
        $inset      = $resolution->insetMm();
        $rectangles = [];
        for ($y = 0; $y < $best->height(); $y++) {
            $run = 0;
            for ($x = 0; $x <= $best->width(); $x++) {
                if ($x < $best->width() && $best->isDark($x, $y)) {
                    $run++;
                    continue;
                }
                if ($run > 0) {
                    $rectangles[] = [$left + ($x - $run) * $module + $inset, $top + $y * $module + $inset, $run * $module - 2 * $inset, $module - 2 * $inset];
                }
                $run = 0;
            }
        }
        $canvas->rectangles($rectangles);

        return [];
    }

    /** @return list<Problem> */
    private static function bars(Canvas $canvas, LabelElement $e, Bars $code, Resolution $resolution, int $number): array
    {
        $widths = $code->widths();
        $total  = array_sum($widths) + 2 * $code->quietModules();
        $module = $resolution->module($e->widthMm / $total, Resolution::BAR_MIN_DOTS, Resolution::BAR_MIN_MM);
        if ($module <= 0.0) {
            return [new Problem(Problem::TOO_SMALL, $number, ['needMm' => round($total * $resolution->minimumMm(Resolution::BAR_MIN_DOTS, Resolution::BAR_MIN_MM), 1), 'dpi' => $resolution->dpi])];
        }

        $textMm   = $e->human ? max(3.0, min($e->heightMm * 0.3, 4.0)) : 0.0;
        $barsMm   = $e->heightMm - $textMm;
        $problems = $barsMm < self::MIN_BAR_HEIGHT_MM ? [Problem::warning(Problem::TOO_FLAT, $number, ['minMm' => self::MIN_BAR_HEIGHT_MM])] : [];
        $left     = $resolution->snap($e->xMm + ($e->widthMm - ($total * $module)) / 2 + $code->quietModules() * $module);

        $rectangles = [];
        $x          = $left;
        $inset      = $resolution->insetMm();
        foreach ($widths as $i => $w) {
            if ($i % 2 === 0) {
                $rectangles[] = [$x + $inset, $e->yMm, $w * $module - 2 * $inset, $barsMm];
            }
            $x += $w * $module;
        }
        $canvas->rectangles($rectangles);

        if ($e->human) {
            $pt = max(TextFitter::MIN_PT, $textMm / PdfDocument::pointsToMm(1.0) * 0.85);
            [$line, $pt] = TextFitter::fit($code->humanText(), $e->widthMm, $pt, false);
            $canvas->text($e->xMm + ($e->widthMm - PdfDocument::textWidthMm($line, $pt)) / 2, $e->yMm + $e->heightMm - $textMm * 0.15, $line, $pt);
        }

        return $problems;
    }
}
