<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * The writes of «Druckregeln» as the model's operations: a new rule at the end of the order, and the takeover of today's
 * preselections (D-1519 R2) — each proposal one record, one act for all (undo per act, D-1410), none twice.
 *
 * ```mermaid
 * flowchart LR
 *   P["proposals (stock: Bauform, Gruppe, Platzart)"] --> D{"same conditions as a rule?"}
 *   D -->|yes| S["skipped"]
 *   D -->|no| W["create_record + set_values, position after the last"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/pakete/druckregeln/konzept.md
 */
final class PrintRuleChanges
{
    /** proposal key ⇒ the field it is written to */
    private const FIELDS = [
        'for' => 'rule_for', 'form' => 'rule_form', 'group' => 'rule_group', 'place_type' => 'rule_place_type',
        'workplace' => 'rule_workplace', 'label' => 'rule_label', 'printer' => 'rule_printer', 'copies' => 'rule_copies',
    ];

    private const STEP = 10;

    public function __construct(private readonly Structure $structure)
    {
    }

    /** @return list<array<string, mixed>> a new rule with its name, after the last one; it answers the ref «regel» */
    public function create(string $name, PrintRules $rules): array
    {
        if (trim($name) === '') {
            throw LabelError::withoutName();
        }

        return $this->written('regel', ['name' => trim($name)], self::last($rules) + self::STEP);
    }

    /**
     * The proposals not yet a rule, in their order: a proposal is taken where no rule asks the same conditions.
     *
     * @param list<array{name: string, for?: int, form?: int, group?: int, place_type?: int, label: int, copies?: int|null}> $proposals
     * @return list<array{name: string, for?: int, form?: int, group?: int, place_type?: int, label: int, copies?: int|null}>
     */
    public static function open(array $proposals, PrintRules $rules): array
    {
        $taken = [];
        foreach ($rules->all() as $rule) {
            $taken[self::key($rule->forNode, $rule->form, $rule->group, $rule->placeType, $rule->workplace)] = true;
        }
        $out = [];
        foreach ($proposals as $proposal) {
            $key = self::key((int) ($proposal['for'] ?? 0), (int) ($proposal['form'] ?? 0), (int) ($proposal['group'] ?? 0), (int) ($proposal['place_type'] ?? 0), 0);
            if ((int) $proposal['label'] > 0 && ! isset($taken[$key])) {
                $taken[$key] = true;
                $out[]       = $proposal;
            }
        }

        return $out;
    }

    /**
     * @param list<array{name: string, for?: int, form?: int, group?: int, place_type?: int, label: int, copies?: int|null}> $proposals
     * @return list<array<string, mixed>>
     */
    public function takeOver(array $proposals, PrintRules $rules): array
    {
        $position = self::last($rules);
        $changes  = [];
        foreach (self::open($proposals, $rules) as $i => $proposal) {
            $position += self::STEP;
            $changes   = array_merge($changes, $this->written('regel' . $i, $proposal, $position));
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $given
     * @return list<array<string, mixed>>
     */
    private function written(string $ref, array $given, int $position): array
    {
        $values = [(string) $this->structure->field('label') => (string) $given['name']];
        if ($this->structure->hasField('position')) {
            $values[(string) $this->structure->field('position')] = (string) $position;
        }
        foreach (self::FIELDS as $key => $field) {
            $value = (int) ($given[$key] ?? 0);
            if ($value > 0 && $this->structure->hasField($field)) {
                $values[(string) $this->structure->field($field)] = (string) $value;
            }
        }

        return [
            ['op' => 'create_record', 'ref' => $ref, 'node' => (string) $this->structure->node('label_rule')],
            ['op' => 'set_values', 'record' => '@' . $ref, 'values' => $values],
        ];
    }

    private static function last(PrintRules $rules): int
    {
        return array_reduce($rules->all(), static fn (int $max, PrintRule $rule): int => max($max, $rule->position), 0);
    }

    private static function key(int $for, int $form, int $group, int $placeType, int $workplace): string
    {
        return implode(':', [$for, $form, $group, $placeType, $workplace]);
    }
}
