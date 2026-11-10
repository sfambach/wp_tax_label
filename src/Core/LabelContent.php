<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * What one label says: the scan code, a title in large type and an optional second line.
 *
 * It takes a kind and an id rather than a stored record, so a label works whether a place is a
 * tree node or a record — the storage model is still being reworked.
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1032
 */
final class LabelContent
{
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $subtitle = '',
    ) {
    }

    /** The made-up label a test print uses (D-1096): long enough to show where text gets cut. */
    public static function sample(): self
    {
        return new self('O-123456', 'R2-F3 · Fach 3 Kleinteile', 'Keller · Regal 2 · Fach 3 · Testdruck');
    }
}
