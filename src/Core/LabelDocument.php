<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

use TaxmodLabel\Core\Drawing\LabelDrawing;
use TaxmodLabel\Core\Drawing\PdfCanvas;
use TaxmodLabel\Core\Drawing\Picture;
use TaxmodLabel\Core\Drawing\Resolution;
use TaxmodLabel\Core\Drawing\SvgCanvas;
use TaxmodLabel\Core\Pdf\PdfDocument;

/**
 * Lays labels out: as a PDF to print, one page per label, or as an SVG for the editor — both from one drawing.
 *
 * ```mermaid
 * flowchart LR
 *   L["LabelLayout + values"] --> D["LabelDrawing"]
 *   D --> P["PdfCanvas → PDF"]
 *   D --> S["SvgCanvas → SVG"]
 *   D --> X["problems"]
 * ```
 *
 * @see docs/konzept.md §5
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1032, D-1153
 */
final class LabelDocument
{
    /**
     * A label of a fixed format from a code and two lines of text — drawn with the shipped layout of its format.
     *
     * @param list<LabelContent> $labels
     */
    public static function render(LabelFormat $format, array $labels): string
    {
        $template = LabelTemplate::forFormat($format->key);

        return self::renderLayout($format, $template->layout, array_map(static fn (LabelContent $l): array => LabelTemplate::valuesOf($l), $labels));
    }

    /**
     * D-1153: the labels drawn by a layout — each element reads its field from the record's values.
     *
     * ⚠️ *Printing refuses a label with an error (a code too small to scan, a value a code cannot take): paper that cannot be
     * scanned is worse than no paper.*
     *
     * @param list<array<string, string>> $records one map field ⇒ text per label
     * @param array<int, Picture>          $pictures media id ⇒ picture
     * @throws LabelProblems
     */
    public static function renderLayout(LabelFormat $format, LabelLayout $layout, array $records, ?Resolution $resolution = null, array $pictures = []): string
    {
        $outside = $layout->outside($format->widthMm, $format->heightMm);
        if ($outside !== []) {
            throw new LabelProblems($outside);
        }
        $resolution ??= new Resolution();

        $pdf    = new PdfDocument();
        $canvas = new PdfCanvas($pdf);
        foreach ($records as $values) {
            $pdf->addPage($format->widthMm, $format->heightMm, $format->turnedOnRoll());
            // D-1190: a printer that starts its lines part-way along the head gets the drawing moved back by that much, the part
            // that falls off the left drawn again at the right end of its line — on paper it lands where the layout says.
            // The shift is rounded to whole dots, so the codes stay on the printer's dot grid (Resolution::insetMm).
            $shifts = $format->offsetMm > 0.0 && $format->lineMm > $format->offsetMm ? [-$resolution->round($format->offsetMm), $resolution->round($format->lineMm - $format->offsetMm)] : [0.0];
            foreach ($shifts as $dx) {
                if ($dx !== 0.0) {
                    $pdf->beginShift($dx);
                }
                $problems = LabelDrawing::draw($canvas, $layout, $values, $format->widthMm, $format->heightMm, $resolution, $pictures);
                if (Problem::hasError($problems)) {
                    throw new LabelProblems(array_values(array_filter($problems, static fn (Problem $p): bool => $p->error)));
                }
                if ($dx !== 0.0) {
                    $pdf->endShift();
                }
            }
        }

        return $pdf->render();
    }

    /**
     * The editor's preview: the label as an SVG and everything wrong with it.
     *
     * @param array<string, string> $values
     * @param array<int, Picture>   $pictures
     * @return array{0: string, 1: list<Problem>}
     */
    public static function svg(float $widthMm, float $heightMm, LabelLayout $layout, array $values, ?Resolution $resolution = null, array $pictures = []): array
    {
        $canvas   = new SvgCanvas($widthMm, $heightMm);
        $problems = [...$layout->outside($widthMm, $heightMm), ...LabelDrawing::draw($canvas, $layout, $values, $widthMm, $heightMm, $resolution ?? new Resolution(), $pictures)];

        return [$canvas->render(), $problems];
    }
}
