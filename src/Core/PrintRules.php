<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * The print rules as the modeller's one rule book reads them (D-1541): each rule's conditions under a key, and what is about to be
 * printed as the nodes that count for each key. Which rule holds is decided there, never here; this class only translates. Read
 * once, asked for every record (CD-7).
 *
 * ```mermaid
 * flowchart LR
 *   R["Druckregeln records"] -->|book| B["ModelDoor::firstRule"]
 *   C["PrintContext"] -->|context| B
 *   B -->|rule id| L["label (+ copies, + printer)"]
 *   B -->|null| N["the way of today"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/pakete/druckregeln/konzept.md
 */
final class PrintRules
{
    /** The condition keys of the rule book — one per fixed condition (R1); place type and workplace hold by equality. */
    public const FOR = 1, FORM = 2, GROUP = 3, PLACE_TYPE = 4, WORKPLACE = 5;

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

    public function get(int $id): ?PrintRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * The rules as the rule book takes them. A rule without a label never holds, so it is not handed in.
     *
     * @return list<array{id: int, position: int, conditions: array<int, int>}>
     */
    public function book(): array
    {
        $out = [];
        foreach ($this->rules as $rule) {
            if ($rule->label > 0) {
                $out[] = ['id' => $rule->id, 'position' => $rule->position, 'conditions' => [
                    self::FOR => $rule->forNode, self::FORM => $rule->form, self::GROUP => $rule->group,
                    self::PLACE_TYPE => $rule->placeType, self::WORKPLACE => $rule->workplace,
                ]];
            }
        }

        return $out;
    }

    /**
     * What is about to be printed, as the rule book asks: each key with the nodes that count — the paths with every node above,
     * place type and workplace as the one value they are (none: nothing counts, so a rule naming one does not hold).
     *
     * @return array<int, list<int>>
     */
    public static function context(PrintContext $context): array
    {
        $one = static fn (int $id): array => $id > 0 ? [$id] : [];

        return [
            self::FOR => $context->nodePath, self::FORM => $context->formPath, self::GROUP => $context->groupPath,
            self::PLACE_TYPE => $one($context->placeType), self::WORKPLACE => $one($context->workplace),
        ];
    }
}
