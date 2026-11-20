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

    /** The rules as the model holds them: one record of «Druckregeln» each; a record without a label is no rule. */
    public static function fromTable(Structure $s, Table $rules): self
    {
        $int = static fn (int $id, string $field): int => $s->hasField($field) ? (int) $rules->first($id, $s->field($field)) : 0;
        $out = [];
        foreach ($rules->ids() as $id) {
            $copies = $s->hasField('rule_copies') ? trim((string) $rules->first($id, $s->field('rule_copies'))) : '';
            $out[]  = new PrintRule(
                $id,
                trim((string) $rules->first($id, $s->field('label'))),
                $int($id, 'rule_label'),
                $int($id, 'position'),
                $int($id, 'rule_for'),
                $int($id, 'rule_form'),
                $int($id, 'rule_group'),
                $int($id, 'rule_place_type'),
                $int($id, 'rule_workplace'),
                $copies === '' ? null : max(1, (int) $copies),
                $int($id, 'rule_printer'),
            );
        }

        return new self($out);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /** @return list<PrintRule> in their order */
    public function all(): array
    {
        return $this->rules;
    }

    /** @param (\Closure(PrintRule): bool)|null $usable what else a rule needs here, e.g. its printer at the workplace */
    public function first(PrintContext $context, ?\Closure $usable = null): ?PrintRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->holdsFor($context) && ($usable === null || $usable($rule))) {
                return $rule;
            }
        }

        return null;
    }
}
