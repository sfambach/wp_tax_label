<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * Thrown where a label cannot be printed as it stands — it carries every problem, not just the first.
 *
 * @see docs/konzept.md §6
 */
final class LabelProblems extends \InvalidArgumentException
{
    /** @param list<Problem> $problems */
    public function __construct(public readonly array $problems)
    {
        $first = $problems[0] ?? null;
        parent::__construct($first === null ? 'The label has problems.' : sprintf('Label problem «%s» at element %d: %s', $first->code, $first->element, json_encode($first->details, JSON_UNESCAPED_UNICODE)));
    }
}
