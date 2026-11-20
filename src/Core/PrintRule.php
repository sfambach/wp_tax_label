<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * One print rule (Druckregeln): what it is for and when it holds, and the label it chooses. Whether it holds is not decided here:
 * the modeller's one rule book does that for every module (D-1541); {@see PrintRules::book()} hands this rule to it.
 *
 * @see wp-taxonomy-modeler docs/pakete/druckregeln/konzept.md
 */
final class PrintRule
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $label,
        public readonly int $position = 0,
        public readonly int $forNode = 0,
        public readonly int $form = 0,
        public readonly int $group = 0,
        public readonly int $placeType = 0,
        public readonly int $workplace = 0,
        public readonly ?int $copies = null,
        public readonly int $printer = 0,
    ) {
    }
}
