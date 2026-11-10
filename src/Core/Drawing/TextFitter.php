<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Drawing;

use TaxmodLabel\Core\Pdf\PdfDocument;

/**
 * Sets a text into a box: shrinks it until it fits, or keeps its size and lets the box grow (Stefan 2026-11-10).
 *
 * @see docs/konzept.md §4
 */
final class TextFitter
{
    public const MIN_PT = 7.0;

    /** Line distance as a share of the type size. */
    public const LEADING = 1.15;

    /**
     * Largest size up to $maxPt at which the words fill at most $maxLines lines; else one line, fitted or cut.
     *
     * @return array{0: list<string>, 1: float}
     */
    public static function wrap(string $text, float $widthMm, float $maxPt, bool $bold, int $maxLines): array
    {
        for ($size = $maxPt; $size >= self::MIN_PT; $size -= 0.5) {
            $lines = self::lines($text, $widthMm, $size, $bold);
            $fits  = count($lines) <= $maxLines;
            foreach ($lines as $line) {
                $fits = $fits && PdfDocument::textWidthMm($line, $size, $bold) <= $widthMm;
            }
            if ($fits) {
                return [$lines, $size];
            }
        }
        [$line, $size] = self::fit($text, $widthMm, $maxPt, $bold);

        return [[$line], $size];
    }

    /**
     * Largest size up to $maxPt at which the text fits one line; below the minimum it is cut with an ellipsis.
     *
     * @return array{0: string, 1: float}
     */
    public static function fit(string $text, float $widthMm, float $maxPt, bool $bold): array
    {
        for ($size = $maxPt; $size >= self::MIN_PT; $size -= 0.5) {
            if (PdfDocument::textWidthMm($text, $size, $bold) <= $widthMm) {
                return [$text, $size];
            }
        }
        $cut = $text;
        while (mb_strlen($cut) > 1 && PdfDocument::textWidthMm($cut . '…', self::MIN_PT, $bold) > $widthMm) {
            $cut = mb_substr($cut, 0, -1);
        }

        return [rtrim($cut) . '…', self::MIN_PT];
    }

    /**
     * The words broken into lines at this size; a word longer than the line stands alone.
     *
     * @return list<string>
     */
    public static function lines(string $text, float $widthMm, float $sizePt, bool $bold): array
    {
        $words   = preg_split('/\s+/u', trim($text)) ?: [$text];
        $lines   = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && PdfDocument::textWidthMm($candidate, $sizePt, $bold) > $widthMm) {
                $lines[] = $current;
                $current = $word;
                continue;
            }
            $current = $candidate;
        }
        $lines[] = $current;

        return $lines;
    }

    /** The height of $count lines at this size, from the top of the first to the baseline of the last. */
    public static function heightMm(int $count, float $sizePt): float
    {
        $line = PdfDocument::pointsToMm($sizePt);

        return $line * (1 + ($count - 1) * self::LEADING);
    }
}
