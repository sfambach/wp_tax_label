<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Pdf;

/**
 * Glyph widths of the two standard PDF fonts used on labels, so text can be fitted without embedding a font.
 *
 * Widths are in thousandths of the font size (Adobe AFM), for WinAnsi bytes 32..126 plus the
 * German letters; anything else counts as a digit width.
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1032
 */
final class HelveticaMetrics
{
    private const REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    private const BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    /** WinAnsi byte => [regular, bold] for the letters outside ASCII that labels actually carry. */
    private const EXTRA = [
        0x85 => [1000, 1000], 0xB7 => [278, 278], 0xC4 => [667, 722], 0xD6 => [778, 778],
        0xDC => [722, 722], 0xDF => [611, 611], 0xE4 => [556, 556], 0xF6 => [556, 611], 0xFC => [556, 611],
    ];

    /** Width in points of WinAnsi-encoded text at the given size. */
    public static function width(string $winAnsi, float $sizePt, bool $bold): float
    {
        $table = $bold ? self::BOLD : self::REGULAR;
        $units = 0;
        foreach (unpack('C*', $winAnsi) ?: [] as $byte) {
            $units += match (true) {
                $byte >= 32 && $byte <= 126 => $table[$byte - 32],
                isset(self::EXTRA[$byte]) => self::EXTRA[$byte][$bold ? 1 : 0],
                default => 556,
            };
        }
        return $units * $sizePt / 1000;
    }
}
