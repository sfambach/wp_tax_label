<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * The one evaluator of the print rules: the rules in their order (position, then id), and the first that holds decides. Read once,
 * asked for every record (CD-7).
 *
 * ```mermaid
 * flowchart LR
 *   C["PrintContext"] --> R{"rule 1 … n, in order"}
 *   R -->|first that holds| L["label (+ copies, + printer)"]
 *   R -->|none| N["null: the way of today"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/pakete/druckregeln/konzept.md
 */
final class PrintRules
{
    /** @var list<PrintRule> */
    private readonly array $rules;

    /** @param list<PrintRule> $rules */
    public function __construct(array $rules)
    {
        usort($rules, static fn (PrintRule $a, PrintRule $b): int => [$a->position, $a->id] <=> [$b->position, $b->id]);
        $this->rules = $rules;
    }

    public function first(PrintContext $context): ?PrintRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->holdsFor($context)) {
                return $rule;
            }
        }

        return null;
    }
}
