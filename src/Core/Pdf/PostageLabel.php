<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Pdf;

use TaxmodLabel\Core\Drawing\Picture;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\LabelFormat;
use TaxmodLabel\Core\LabelPrinter;

/**
 * D-1517: a postage label (DHL label, Internetmarke) as the print service prints it — the picture the browser cropped from
 * the PDF, upright, as large as fits on a 100 mm label across the printer's roll — the size the browser's print gave it.
 *
 * ```mermaid
 * flowchart LR
 *   B["browser: PDF → cropped PNG, upright"] --> P["PostageLabel::pdf"] --> F["format rollMm × 100 mm"] --> J["print job, workplace default printer"]
 * ```
 *
 * @see docs/konzept.md §7
 */
final class PostageLabel
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Kept clear at each side of the roll; the QL-810W cannot print its outer 1.5 mm (as label62.js). */
    public const SIDE_MM = 2.0;

    /** Kept clear at the start and end of the label; the QL-810W leaves about 3 mm unprinted there. */
    public const END_MM = 4.5;

    /** The page the Brother driver takes for endless 62 mm tape; a longer one it shrinks to fit (label62.js PAGE_MM). */
    public const LENGTH_MM = 100.0;

    /** A cropped label at 300 dpi is some hundred pixels across; fewer is no label, more no postage label. */
    public const MIN_PX = 100;

    public const MAX_PX = 6000;

    /** Longer than this across its width is not a postage label but a strip of something. */
    public const MAX_RATIO = 6.0;

    public const FORMAT_KEY = 'postage';

    /**
     * The label's format on this printer: across its roll, LENGTH_MM along it — the page the Brother driver takes for endless
     * tape, as the browser's print had it (label62.js PAGE_MM), so the label prints as large as before; turned like any label as
     * wide as the roll, unless the printer takes labels as they are (D-1191).
     *
     * @throws LabelError
     */
    public static function format(Picture $picture, LabelPrinter $printer): LabelFormat
    {
        self::plausible($picture);
        if ($printer->rollMm <= 2 * self::SIDE_MM) {
            throw LabelError::notAPostagePicture('no roll');
        }

        return new LabelFormat(self::FORMAT_KEY, $printer->rollMm, self::LENGTH_MM, $printer->rollMm, $printer->turnsLabel ? null : false);
    }

    /**
     * The picture as large as fits — the roll less its sides across, the label less its ends along — centred across, from the
     * top end, as the browser's print laid it.
     *
     * @return array{pdf: string, format: LabelFormat}
     * @throws LabelError
     */
    public static function pdf(Picture $picture, LabelPrinter $printer): array
    {
        $format = self::format($picture, $printer);
        $width  = min($format->widthMm - 2 * self::SIDE_MM, (self::LENGTH_MM - 2 * self::END_MM) * $picture->widthPx / $picture->heightPx);
        $height = $width * $picture->heightPx / $picture->widthPx;
        $pdf    = new PdfDocument();
        $pdf->addPage($format->widthMm, $format->heightMm, $format->turnedOnRoll());
        $pdf->image(($format->widthMm - $width) / 2, self::END_MM, $width, $height, $picture->forPdf(), $picture->widthPx, $picture->heightPx);

        return ['pdf' => $pdf->render(), 'format' => $format];
    }

    /** @throws LabelError */
    public static function picture(string $png): Picture
    {
        if (strlen($png) > self::MAX_BYTES) {
            throw LabelError::notAPostagePicture('larger than 5 MB');
        }
        if (! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw LabelError::notAPostagePicture('no PNG');
        }
        try {
            return Picture::fromBytes($png);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            throw LabelError::notAPostagePicture($e->getMessage());
        }
    }

    /** @throws LabelError */
    private static function plausible(Picture $picture): void
    {
        $short = min($picture->widthPx, $picture->heightPx);
        $long  = max($picture->widthPx, $picture->heightPx);
        if ($short < self::MIN_PX || $long > self::MAX_PX || $long > $short * self::MAX_RATIO) {
            throw LabelError::notAPostagePicture(sprintf('%d × %d px', $picture->widthPx, $picture->heightPx));
        }
    }
}
