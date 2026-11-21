<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * A label record created, changed or removed as one change list (D-1239): its name, size, roll, copies and layout, and the rolls
 * that allow it — so a label is changed as data, without a release.
 *
 * ⚠️ *His word 2026-11-08: «die Schnittstelle für Etiketten optimieren … direkt Etiketten auch noch anlegen könnten, löschen, und
 * ändern. Ich meine, das ist ja alles in JSON abgelegt.»*
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1239
 */
final class LabelChanges
{
    /** What the caller names ⇒ the structure key of its field. */
    public const FIELDS = [
        'name'   => 'label',
        'key'    => 'template_key',
        'width'  => 'template_width',
        'height' => 'template_height',
        'roll'   => 'template_roll',
        'copies' => 'template_copies',
        'layout' => 'template_layout',
    ];

    public function __construct(private readonly Structure $structure)
    {
    }

    /** The sizes, in millimetres. */
    public const SIZES = ['width', 'height', 'roll'];

    /**
     * The values a caller gave, by field id; a key the model has no field for is left out, a size must be a whole number above 0.
     * Feldkatalog 9/10/12: a size whose field holds unit values is left out here — {@see self::amounts()} gives it.
     *
     * @param array<string, mixed> $given name, key, width, height, roll, copies, layout
     * @param list<int> $amountFields the fields that hold unit values
     * @return array<string, string> field id ⇒ text
     */
    public function values(array $given, array $amountFields = []): array
    {
        $out = [];
        foreach (self::FIELDS as $name => $key) {
            if (! array_key_exists($name, $given) || ! $this->structure->hasField($key)) {
                continue;
            }
            $value = is_scalar($given[$name]) ? trim((string) $given[$name]) : '';
            if (in_array($name, ['width', 'height', 'roll', 'copies'], true) && $value !== '' && (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1)) {
                throw LabelError::notALabelSize($name . '=' . $value);
            }
            if ($name === 'name' && $value === '') {
                throw LabelError::withoutName();
            }
            if (in_array($name, self::SIZES, true) && in_array($this->structure->field($key), $amountFields, true)) {
                continue;
            }
            $out[(string) $this->structure->field($key)] = $value;
        }

        return $out;
    }

    /**
     * Feldkatalog 9/10/12: the sizes given for fields that hold unit values, in the base unit metre — the modeller writes the part.
     * An empty size is left out; a size that is no whole number above 0 is refused as in {@see self::values()}.
     *
     * @param array<string, mixed> $given
     * @param list<int> $amountFields
     * @return array<int, float> field id ⇒ metres
     */
    public function amounts(array $given, array $amountFields): array
    {
        $this->values(array_intersect_key($given, array_flip(self::SIZES)));
        $out = [];
        foreach (self::SIZES as $name) {
            $key   = self::FIELDS[$name];
            $value = is_scalar($given[$name] ?? null) ? trim((string) $given[$name]) : '';
            if ($value !== '' && $this->structure->hasField($key) && in_array($this->structure->field($key), $amountFields, true)) {
                $out[$this->structure->field($key)] = (int) $value / UnitAmounts::MILLIMETRE;
            }
        }

        return $out;
    }

    /** @return list<int> the size fields this model has, to ask which of them hold unit values */
    public function sizeFields(): array
    {
        return array_values(array_map(fn (string $name): int => $this->structure->field(self::FIELDS[$name]), array_filter(self::SIZES, fn (string $name): bool => $this->structure->hasField(self::FIELDS[$name]))));
    }

    /**
     * A new label in a category of «Etiketten» (or «Etiketten» itself); it answers the ref «etikett».
     *
     * @param array<string, mixed> $given
     * @param list<int> $rolls the rolls that allow it from now on
     * @param list<int> $amountFields the fields that hold unit values, written apart ({@see self::amounts()})
     * @return list<array<string, mixed>>
     */
    public function create(int $node, array $given, array $rolls = [], ?Table $rollTable = null, array $amountFields = []): array
    {
        if (trim((string) ($given['name'] ?? '')) === '') {
            throw LabelError::withoutName();
        }

        return array_merge(
            [
                ['op' => 'create_record', 'ref' => 'etikett', 'node' => (string) $node],
                ['op' => 'set_values', 'record' => '@etikett', 'values' => $this->values($given, $amountFields)],
            ],
            $this->allowOn('@etikett', $rolls, $rollTable),
        );
    }

    /**
     * @param array<string, mixed> $given only what changes
     * @param list<int>|null $rolls exactly the rolls that allow it from now on — it leaves the others; null: the rolls stay as they are
     * @return list<array<string, mixed>>
     */
    public function update(int $label, array $given, ?array $rolls = null, ?Table $rollTable = null, array $amountFields = []): array
    {
        $values  = $this->values($given, $amountFields);
        $changes = $values === [] ? [] : [['op' => 'set_values', 'record' => (string) $label, 'values' => $values]];
        if ($rolls === null) {
            return $changes;
        }
        $leaving = [];
        foreach ($this->rollsAllowing($label, $rollTable) as $roll) {
            if (! in_array($roll, $rolls, true)) {
                $kept      = array_values(array_filter($this->allowed($roll, $rollTable), static fn (string $id): bool => $id !== (string) $label));
                $leaving[] = ['op' => 'set_values', 'record' => (string) $roll, 'values' => [(string) $this->structure->field('roll_labels') => ['values' => $kept]]];
            }
        }

        return array_merge($changes, $leaving, $this->allowOn((string) $label, $rolls, $rollTable));
    }

    /**
     * The label out of every roll that allows it, then the record itself. What prints with it elsewhere (a place type of the stock)
     * keeps it: `$users` names those, and then nothing is removed.
     *
     * @param list<string> $users
     * @return list<array<string, mixed>>
     */
    public function remove(int $label, ?Table $rollTable = null, array $users = []): array
    {
        if ($users !== []) {
            throw LabelError::inUse(implode(', ', $users));
        }

        $changes = [];
        foreach ($this->rollsAllowing($label, $rollTable) as $roll) {
            $kept      = array_values(array_filter($this->allowed($roll, $rollTable), static fn (string $id): bool => $id !== (string) $label));
            $changes[] = ['op' => 'set_values', 'record' => (string) $roll, 'values' => [(string) $this->structure->field('roll_labels') => ['values' => $kept]]];
        }
        $changes[] = ['op' => 'remove_record', 'record' => (string) $label];

        return $changes;
    }

    /** @return list<int> the rolls whose list of labels names this one */
    public function rollsAllowing(int $label, ?Table $rollTable): array
    {
        if ($rollTable === null || ! $this->structure->hasField('roll_labels')) {
            return [];
        }

        return array_values(array_filter($rollTable->ids(), fn (int $roll): bool => in_array((string) $label, $this->allowed($roll, $rollTable), true)));
    }

    /**
     * @param list<int> $rolls
     * @return list<array<string, mixed>>
     */
    private function allowOn(string $label, array $rolls, ?Table $rollTable): array
    {
        if ($rolls === [] || $rollTable === null || ! $this->structure->hasField('roll_labels')) {
            return [];
        }
        $changes = [];
        foreach (array_unique($rolls) as $roll) {
            if (! $rollTable->has($roll)) {
                throw LabelError::notARoll((string) $roll);
            }
            $allowed = $this->allowed($roll, $rollTable);
            if (in_array($label, $allowed, true)) {
                continue;
            }
            $changes[] = ['op' => 'set_values', 'record' => (string) $roll, 'values' => [(string) $this->structure->field('roll_labels') => ['values' => array_merge($allowed, [$label])]]];
        }

        return $changes;
    }

    /** @return list<string> */
    private function allowed(int $roll, Table $rollTable): array
    {
        return array_values(array_map('strval', $rollTable->all($roll, $this->structure->field('roll_labels'))));
    }
}
