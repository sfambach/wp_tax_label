<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * A component value in the short code printed on tiny parts and their labels: the unit prefix stands where the decimal
 * comma would be (IEC 60062) — 24 kΩ is «24k», 4,7 kΩ «4k7», 0,22 Ω «0R22», 100 nF «100n», 4,7 µF «4µ7».
 *
 * ⚠️ *His choice 2026-11-07 on the card «Wie soll der Wert auf dem SMD-Etikett stehen?»: «Kurz: 24K», and: «Wobei das K hier
 * dann das Komma auch ist, ne? Und bei Kondensatoren … N oder U».* A text that is not a number with a unit stays as it is.
 * ⚠️ *His word 2026-11-08: «Nano-Pico und Kilo werden alle klein geschrieben, also kleines K» — «Mikro ist ein Mikrozeichen oder ein kleines U»; «dass man das auch aus den Stammdaten natürlich nimmt» — the prefix stays as the value is written (D-1236).*
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1217, D-1236
 */
final class ShortValue
{
    /** The prefix as the master data writes it; only the Greek mu becomes the micro sign every label font has. An ohm value without a prefix takes «R». */
    private const LETTERS = ['μ' => 'µ'];

    public static function of(string $value): string
    {
        $text = trim($value);
        if (! preg_match('/^(\d+)(?:[.,](\d+))?\s*([pnµμumkKMG])?\s*(Ω|Ohm|ohm|R|F|H|V|A|W)?$/u', $text, $m)) {
            return $text;
        }
        $whole    = $m[1];
        $fraction = rtrim($m[2] ?? '', '0');
        $prefix   = $m[3] ?? '';
        $unit     = $m[4] ?? '';
        if ($prefix === '' && $unit === '') {
            return $text;
        }
        if ($prefix !== '') {
            $letter = self::LETTERS[$prefix] ?? $prefix;
        } elseif (in_array($unit, ['Ω', 'Ohm', 'ohm', 'R'], true)) {
            $letter = 'R';
        } else {
            return $text;
        }

        return $fraction === '' ? $whole . $letter : $whole . $letter . $fraction;
    }
}
