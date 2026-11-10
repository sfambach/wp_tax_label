<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * Where the labels, the printers and the rolls live in the model: node and field ids, read from one option.
 *
 * ⚠️ *D-1292 and the architecture thread 2026-11-10: every id has one owner. These keys came from the stock module's structure
 * and leave it in the same release that switches to this module.*
 *
 * The module never names a node by its label (code standard); `structure-live.json` holds the ids of fambach.net.
 *
 * @see docs/konzept.md §2
 */
final class Structure
{
    /** D-1153, D-1159: the labels stand in «Etiketten» and its categories. */
    public const NODES = ['label_template'];

    /** D-1190, D-1192: the printers and the rolls they hold. */
    private const OPTIONAL_NODES = ['label_printer', 'label_roll'];

    /** `label` is the name of a record — read here, owned by the modeller's shipped name field. */
    public const FIELDS = ['label', 'template_key', 'template_width', 'template_height', 'template_layout'];

    private const OPTIONAL_FIELDS = [
        'template_roll', 'template_copies',
        // D-1191, D-1192, D-1201, D-1231, D-1234: every print setting is the printer's.
        'printer_queue', 'printer_turns', 'printer_options', 'printer_turn_options', 'printer_sizes', 'printer_offset', 'printer_line',
        'printer_loaded_roll', 'printer_setup', 'printer_active', 'printer_retry',
        // Assumed (docs/konzept.md §10, open question): the printer's resolution; without it 203 dpi.
        'printer_dpi',
        'roll_width', 'roll_labels',
    ];

    /**
     * @param array<string, int> $nodes
     * @param array<string, int> $fields
     */
    private function __construct(private readonly array $nodes, private readonly array $fields)
    {
    }

    /** @param array<string, mixed> $raw the decoded JSON or the stored option */
    public static function fromArray(array $raw): self
    {
        return new self(self::ids($raw, 'nodes', [...self::NODES, ...self::OPTIONAL_NODES]), self::ids($raw, 'fields', [...self::FIELDS, ...self::OPTIONAL_FIELDS]));
    }

    /**
     * The stored ids, completed by the bundled ones where both agree on every id they share (the rule of the other modules).
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $bundled
     * @return array<string, mixed>
     */
    public static function completed(array $stored, array $bundled): array
    {
        foreach (['nodes', 'fields'] as $group) {
            foreach (is_array($stored[$group] ?? null) ? $stored[$group] : [] as $key => $id) {
                if (isset($bundled[$group][$key]) && (int) $bundled[$group][$key] !== (int) $id) {
                    return $stored;
                }
            }
        }
        foreach (['nodes', 'fields'] as $group) {
            $stored[$group] = (is_array($stored[$group] ?? null) ? $stored[$group] : []) + (is_array($bundled[$group] ?? null) ? $bundled[$group] : []);
        }

        return $stored;
    }

    /** @return list<string> the keys still missing, e.g. «nodes.label_template» */
    public function missing(): array
    {
        $out = [];
        foreach (self::NODES as $key) {
            isset($this->nodes[$key]) || $out[] = 'nodes.' . $key;
        }
        foreach (self::FIELDS as $key) {
            isset($this->fields[$key]) || $out[] = 'fields.' . $key;
        }

        return $out;
    }

    public function node(string $key): int
    {
        return $this->nodes[$key] ?? throw LabelError::notConfigured('nodes.' . $key);
    }

    public function field(string $key): int
    {
        return $this->fields[$key] ?? throw LabelError::notConfigured('fields.' . $key);
    }

    public function hasNode(string $key): bool
    {
        return isset($this->nodes[$key]);
    }

    public function hasField(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    /** @return array{nodes: array<string, int>, fields: array<string, int>} */
    public function toArray(): array
    {
        return ['nodes' => $this->nodes, 'fields' => $this->fields];
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string>         $keys
     * @return array<string, int>
     */
    private static function ids(array $raw, string $group, array $keys): array
    {
        $given = is_array($raw[$group] ?? null) ? $raw[$group] : [];
        $out   = [];
        foreach ($keys as $key) {
            $id = (int) ($given[$key] ?? 0);
            if ($id > 0) {
                $out[$key] = $id;
            }
        }

        return $out;
    }
}
