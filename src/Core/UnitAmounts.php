<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * Feldkatalog 9/10/12: the sizes of labels, printers and rolls read either as the old plain number or as a unit value — the
 * modeller converts a unit value into its base unit (`ModelDoor::amountsOf`, the one place that knows prefixes), this turns it
 * into the module's unit and puts it where the plain number stood, so every reader stays as it is.
 *
 * ```mermaid
 * flowchart LR
 *   T["record values: 62 · or the part's id"] --> W{"amount for it?"}
 *   A["amountsOf: 0.062 m"] --> W
 *   W -->|yes| U["0.062 × 1000 → 62"]
 *   W -->|no| O["62 as it was"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1555
 */
final class UnitAmounts
{
    /** A millimetre in the base unit metre. */
    public const MILLIMETRE = 1000.0;

    /** Dots per inch are their own base unit. */
    public const AS_IS = 1.0;

    /** The structure keys read as sizes, with the factor from the base unit. */
    public const FIELDS = [
        'template_width'  => self::MILLIMETRE,
        'template_height' => self::MILLIMETRE,
        'template_roll'   => self::MILLIMETRE,
        'printer_offset'  => self::MILLIMETRE,
        'printer_line'    => self::MILLIMETRE,
        'roll_width'      => self::MILLIMETRE,
        'printer_dpi'     => self::AS_IS,
    ];

    /** @return list<int> the size fields this model has */
    public static function fields(Structure $s): array
    {
        return array_values(array_map(static fn (string $key): int => $s->field($key), array_filter(array_keys(self::FIELDS), $s->hasField(...))));
    }

    /**
     * @param array<int, array<int, float>> $amounts record → field → amount in the base unit; only where the value is a unit value
     */
    public static function into(Structure $s, Table $table, array $amounts): Table
    {
        if ($amounts === []) {
            return $table;
        }
        $factors = [];
        foreach (self::FIELDS as $key => $factor) {
            if ($s->hasField($key)) {
                $factors[$s->field($key)] = $factor;
            }
        }
        $records = [];
        foreach ($table->ids() as $id) {
            $values = $table->valuesOf($id);
            foreach (array_intersect_key($amounts[$id] ?? [], $factors) as $field => $amount) {
                $values[$field] = [self::number($amount * $factors[$field])];
            }
            $records[$id] = $values;
        }

        return new Table($records);
    }

    /** A whole number without a point, else the decimal as it is — never a float's long tail. */
    private static function number(float $value): string
    {
        $rounded = round($value, 6);

        return abs($rounded - round($rounded)) < 1e-9 ? (string) (int) round($rounded) : rtrim(rtrim(sprintf('%.6F', $rounded), '0'), '.');
    }
}
