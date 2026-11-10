<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Drawing;

/**
 * The printer's dots: a code's modules and bars are whole dots wide, or the printer rounds them unevenly and bars merge.
 *
 * ⚠️ *Stefan 2026-11-10: a code was once too small to scan, «die Auflösung der Drucker nicht immer so hoch» — so every module
 * is snapped down to whole dots and refused below a minimum.*
 *
 * ```mermaid
 * flowchart LR
 *   B["box ÷ modules"] --> D["× dots per mm, rounded down"] --> M{"≥ minimum?"}
 *   M -- yes --> S["module = dots ÷ dots per mm"]
 *   M -- no --> P["problem: too small"]
 * ```
 *
 * @see docs/konzept.md §4 Scanbarkeit
 */
final class Resolution
{
    /** The worst printer in the house (Phomemo M220L) — what the editor reckons with when no printer is known. */
    public const DEFAULT_DPI = 203.0;

    /** A module of a 2D code: at least this many dots and this many mm. */
    public const MATRIX_MIN_DOTS = 3;
    public const MATRIX_MIN_MM   = 0.33;

    /** A narrow bar of a 1D code. */
    public const BAR_MIN_DOTS = 2;
    public const BAR_MIN_MM   = 0.25;

    public function __construct(public readonly float $dpi = self::DEFAULT_DPI)
    {
        if ($dpi < 50.0 || $dpi > 2400.0) {
            throw new \InvalidArgumentException(sprintf('%s dpi is not a printer resolution.', $dpi));
        }
    }

    public function dotsPerMm(): float
    {
        return $this->dpi / 25.4;
    }

    /** The module width in mm at whole dots, or 0.0 where it would fall under the minimum. */
    public function module(float $wantedMm, int $minDots, float $minMm): float
    {
        $dots = (int) floor($wantedMm * $this->dotsPerMm() + 1e-6);
        $need = max($minDots, (int) ceil($minMm * $this->dotsPerMm() - 1e-6));

        return $dots >= $need ? $dots / $this->dotsPerMm() : 0.0;
    }

    /** A position moved up to the next whole dot, so a code's edges fall between dots, not across them. */
    public function snap(float $mm): float
    {
        return ceil($mm * $this->dotsPerMm() - 1e-6) / $this->dotsPerMm();
    }

    /**
     * How far a code's dark box is drawn inside its dots. A printer, like PDF and PostScript, blackens every dot a shape
     * touches; a bar ending exactly on a dot's edge would take the next dot too, bars would swell and spaces close
     * (measured 2026-11-10 at 203 dpi: three-dot bars came out four dots, spaces two). A quarter dot in is safe either way.
     */
    public function insetMm(): float
    {
        return 0.25 / $this->dotsPerMm();
    }

    /** A distance rounded to whole dots. */
    public function round(float $mm): float
    {
        return round($mm * $this->dotsPerMm()) / $this->dotsPerMm();
    }

    /** The smallest module in mm this printer accepts. */
    public function minimumMm(int $minDots, float $minMm): float
    {
        return max($minDots, (int) ceil($minMm * $this->dotsPerMm() - 1e-6)) / $this->dotsPerMm();
    }
}
