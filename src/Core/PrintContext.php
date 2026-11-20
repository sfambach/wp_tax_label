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
    ) {
    }
}
