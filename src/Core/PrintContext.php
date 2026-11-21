<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * What is about to be printed, as the print rules ask about it: the record's node and its ancestors, its Bauform and the Bauform's
 * ancestors (D-1284: «SMD» holds for «0603»), its material group and the group's ancestors, its place type and the workplace.
 *
 * @see wp-taxonomy-modeler docs/pakete/druckregeln/konzept.md
 */
final class PrintContext
{
    /**
     * @param list<int> $nodePath  the record's node first, then its ancestors
     * @param list<int> $formPath  the Bauform first, then its ancestors; [] without one
     * @param list<int> $groupPath the material group first, then its ancestors; [] without one
     */
    public function __construct(
        public readonly array $nodePath,
        public readonly array $formPath = [],
        public readonly array $groupPath = [],
        public readonly int $placeType = 0,
        public readonly int $workplace = 0,
        /** D-1548: the material record, whose values the filters read; 0 for a place */
        public readonly int $record = 0,
        /** @var array<int, string> D-1570: values the module hands in for a rule that reads its label from a field — field → value */
        public readonly array $from = [],
        /** @var array<int, list<string>> D-1576: values of the stock the module hands in — field → values, e.g. where the lots lie */
        public readonly array $stock = [],
    ) {
    }

    /**
     * From what a module asks with (D-1296: the stock does not know this class): `nodes`, `forms`, `groups` (lists of node ids,
     * nearest first), `place_type`, `record` (the material, D-1548), `from` (field → value, D-1570) and `stock` (field → values, D-1576);
     * the workplace is the catalog's own.
     *
     * @param array<string, mixed> $asked
     */
    public static function fromArray(array $asked, int $workplace): self
    {
        $ids = static fn (string $key): array => array_values(array_filter(array_map('intval', is_array($asked[$key] ?? null) ? $asked[$key] : [])));

        $from = [];
        foreach (is_array($asked['from'] ?? null) ? $asked['from'] : [] as $field => $value) {
            if ((int) $field > 0 && is_scalar($value)) {
                $from[(int) $field] = (string) $value;
            }
        }

        $stock = [];
        foreach (is_array($asked['stock'] ?? null) ? $asked['stock'] : [] as $field => $values) {
            if ((int) $field > 0 && is_array($values)) {
                $stock[(int) $field] = array_values(array_map('strval', array_filter($values, 'is_scalar')));
            }
        }

        return new self($ids('nodes'), $ids('forms'), $ids('groups'), (int) ($asked['place_type'] ?? 0), $workplace, (int) ($asked['record'] ?? 0), $from, $stock);
    }
}
