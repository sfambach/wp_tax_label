<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * A workplace (D-1502): a shared record naming the printers one has there, the one a dialog starts with and a place in the
 * stock. A person chooses theirs; the print dialog offers only its printers.
 *
 * @see wp-taxonomy-modeler docs/pakete/arbeitsplatz/konzept.md
 */
final class Workplace
{
    /** @param list<int> $printers */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $printers,
        public readonly int $defaultPrinter = 0,
        public readonly int $location = 0,
    ) {
    }

    /** The workplace `$id` as the model keeps it; null where it is no record of «Arbeitsplätze» (deleted, or never one). */
    public static function from(Structure $s, Table $places, int $id): ?self
    {
        if ($id <= 0 || ! $places->has($id)) {
            return null;
        }
        $one = static fn (string $field): int => $s->hasField($field) ? (int) $places->first($id, $s->field($field)) : 0;

        return new self(
            $id,
            trim((string) $places->first($id, $s->field('label'))),
            $s->hasField('workplace_printers') ? array_values(array_filter(array_map('intval', $places->all($id, $s->field('workplace_printers'))))) : [],
            $one('workplace_default_printer'),
            $one('workplace_location'),
        );
    }
}
