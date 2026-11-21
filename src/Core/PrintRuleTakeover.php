<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * Today's label preselections as print rules of the one rule book (D-1552): the group becomes the rule's node, a Bauform a filter
 * of kind «Feld», a Platzart a filter of kind «Bestand»; what it is for, label and copies stay the rule's own fields («Wofür» is the
 * rule's own field, Prüftor 21.11.). A preselection naming «für» without that field is only listed; one whose rule stands is left out.
 *
 * ```mermaid
 * flowchart LR
 *   P["proposal"] --> F{"«für» set?"}
 *   F -->|yes| O["open: listed, no operation"]
 *   F -->|no| D{"same node and filters as a rule?"}
 *   D -->|yes| S["skipped"]
 *   D -->|no| W["create_record with node, filters, label, copies"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1552
 */
final class PrintRuleTakeover
{
    private const STEP = 10;

    public function __construct(private readonly Structure $structure)
    {
    }

    /**
     * @param list<array{name: string, for?: int, form?: int, group?: int, place_type?: int, label: int, copies?: int|null}> $proposals
     * @return array{changes: list<array<string, mixed>>, open: list<array{proposal: int, reason: string}>} `proposal` is the index in `$proposals`
     */
    public function of(array $proposals, PrintRules $rules): array
    {
        $s = $this->structure;

        if (! $s->hasNode('label_rule') || ! $s->hasField('rule_node') || ! $s->hasField('rule_label')) {
            throw LabelError::notConfigured('nodes.label_rule, fields.rule_node, fields.rule_label');
        }

        $taken = [];
        foreach ($rules->all() as $rule) {
            $taken[self::key($rule->forNode, $rule->node, $rule->filters)] = true;
        }

        $position = array_reduce($rules->all(), static fn (int $max, PrintRule $rule): int => max($max, $rule->position), 0);
        $changes  = [];
        $open     = [];

        foreach ($proposals as $i => $proposal) {
            if ((int) ($proposal['for'] ?? 0) > 0 && ! $s->hasField('rule_for')) {
                $open[] = ['proposal' => $i, 'reason' => 'for'];
                continue;
            }

            if ((int) $proposal['label'] <= 0) {
                continue;
            }

            $filters = $this->filters($proposal);

            if ($filters === null) {
                $open[] = ['proposal' => $i, 'reason' => 'structure'];
                continue;
            }

            $key = self::key((int) ($proposal['for'] ?? 0), (int) ($proposal['group'] ?? 0), array_map(static fn (array $f): array => ['field' => $f['field'], 'value' => $f['value'], 'operator' => PrintRules::EQUALS], $filters));

            if (isset($taken[$key])) {
                continue;
            }

            $taken[$key] = true;
            $position   += self::STEP;
            $changes[]   = ['op' => 'create_record', 'ref' => 'regel' . $i, 'node' => $s->node('label_rule'), 'values' => $this->values($proposal, $filters, $position)];
        }

        return ['changes' => $changes, 'open' => $open];
    }

    /**
     * The proposal's conditions as filters; null where a field or kind they need is not in the structure.
     *
     * @param array<string, mixed> $proposal
     * @return list<array{kind: int, field: int, value: string}>|null
     */
    private function filters(array $proposal): ?array
    {
        $s    = $this->structure;
        $out  = [];
        $want = [
            ['form', 'filter_kind_field', 'material_form'],
            ['place_type', 'filter_kind_stock', 'location_place_type'],
        ];

        foreach ($want as [$key, $kind, $field]) {
            $value = (int) ($proposal[$key] ?? 0);

            if ($value <= 0) {
                continue;
            }

            if (! $s->hasNode($kind) || ! $s->hasField($field) || ! $s->hasField('rule_filters') || ! $s->hasField('filter_field') || ! $s->hasField('filter_value') || ! $s->hasField('filter_kind')) {
                return null;
            }

            $out[] = ['kind' => $s->node($kind), 'field' => $s->field($field), 'value' => (string) $value];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>                              $proposal
     * @param list<array{kind: int, field: int, value: string}> $filters
     * @return array<string, mixed>
     */
    private function values(array $proposal, array $filters, int $position): array
    {
        $s      = $this->structure;
        $values = [(string) $s->field('label') => (string) $proposal['name'], (string) $s->field('rule_label') => (string) (int) $proposal['label']];

        if ((int) ($proposal['for'] ?? 0) > 0) {
            $values[(string) $s->field('rule_for')] = (string) (int) $proposal['for'];
        }

        if ((int) ($proposal['group'] ?? 0) > 0) {
            $values[(string) $s->field('rule_node')] = (string) (int) $proposal['group'];
        }

        if ($s->hasField('position')) {
            $values[(string) $s->field('position')] = (string) $position;
        }

        if ((int) ($proposal['copies'] ?? 0) > 0 && $s->hasField('rule_copies')) {
            $values[(string) $s->field('rule_copies')] = (string) (int) $proposal['copies'];
        }

        // The operator stays empty: an empty operator is «gleich» (D-1541).
        $parts = [];
        foreach ($filters as $n => $filter) {
            $parts['f' . $n] = [
                (string) $s->field('filter_kind')  => (string) $filter['kind'],
                (string) $s->field('filter_field') => (string) $filter['field'],
                (string) $s->field('filter_value') => $filter['value'],
            ];
        }

        if ($parts !== []) {
            $values[(string) $s->field('rule_filters')] = $parts;
        }

        return $values;
    }

    /** @param list<array{field: int, value: string, operator: string}> $filters */
    private static function key(int $for, int $node, array $filters): string
    {
        $words = array_map(static fn (array $f): string => $f['field'] . '=' . $f['value'] . ':' . $f['operator'], $filters);
        sort($words);

        return $for . '|' . $node . '|' . implode(',', $words);
    }
}
