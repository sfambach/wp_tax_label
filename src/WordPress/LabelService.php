<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\WordPress\ModelDoor;
use TaxmodLabel\Core\Drawing\Picture;
use TaxmodLabel\Core\LabelCatalog;
use TaxmodLabel\Core\LabelChanges;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\Structure;
use TaxmodLabel\Core\Table;

/**
 * The labels, printers and rolls as the model holds them — read and written only through the modeller's door (D-1186, D-1292).
 *
 * ```mermaid
 * flowchart LR
 *   S["LabelService"] -->|children · recordsOf · valuesOf · write| D["ModelDoor"]
 *   S --> C["LabelCatalog (labels, printers, rolls)"]
 *   S --> P["pictures from the media library"]
 * ```
 *
 * @see docs/konzept.md §2
 */
final class LabelService
{
    public const STRUCTURE = 'taxmod_label_structure';

    /** Filter: `(array<int, list<string>> $users): array<int, list<string>>` — label id ⇒ what prints with it elsewhere (a stock place type), by name; asked once (CD-7). */
    public const USERS_FILTER = 'taxmod_label_users';

    private ?Structure $structure = null;

    private ?LabelCatalog $catalog = null;

    /** @var array{table: Table, categories: array<int, string>, node_of: array<int, int>, root: int}|null */
    private ?array $records = null;

    /** @var array<int, Table> */
    private array $tables = [];

    /** @var array<int, list<string>>|null */
    private ?array $users = null;

    public function __construct(private readonly ModelDoor $door = new ModelDoor())
    {
    }

    public function structure(): Structure
    {
        if ($this->structure !== null) {
            return $this->structure;
        }
        $stored  = get_option(self::STRUCTURE, []);
        $file    = dirname(__DIR__, 2) . '/structure-live.json';
        $bundled = is_readable($file) ? json_decode((string) file_get_contents($file), true) : [];

        return $this->structure = Structure::fromArray(Structure::completed(is_array($stored) ? $stored : [], is_array($bundled) ? $bundled : []));
    }

    /** D-1160: the labels stand in categories, the sub-nodes of «Etiketten» — read all of them in one go (CD-7). */
    public function records(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }
        $root       = $this->structure()->node('label_template');
        $categories = $this->door->children($root);
        $nodeOf     = $this->door->recordsOf(array_merge([$root], array_keys($categories)), 500);

        return $this->records = [
            'table'      => new Table($nodeOf === [] ? [] : $this->door->valuesOf(array_keys($nodeOf))),
            'categories' => $categories,
            'node_of'    => $nodeOf,
            'root'       => $root,
        ];
    }

    public function catalog(): LabelCatalog
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        ['table' => $table, 'categories' => $categories, 'node_of' => $nodeOf] = $this->records();

        return $this->catalog = LabelCatalog::from(
            $this->structure(),
            $table,
            array_map(static fn (int $node): string => $categories[$node] ?? '', $nodeOf),
            $nodeOf,
            $this->structure()->hasNode('label_printer') ? $this->table('label_printer') : null,
            $this->rollTable(),
        );
    }

    public function table(string $node): Table
    {
        $id = $this->structure()->node($node);

        return $this->tables[$id] ??= new Table($this->door->records($id));
    }

    public function rollTable(): ?Table
    {
        return $this->structure()->hasNode('label_roll') ? $this->table('label_roll') : null;
    }

    /** @return array<int, string> record id ⇒ name, by name */
    public function names(string $node): array
    {
        if (! $this->structure()->hasNode($node)) {
            return [];
        }
        $table = $this->table($node);
        $name  = $this->structure()->field('label');
        $out   = [];
        foreach ($table->ids() as $id) {
            $out[$id] = trim((string) ($table->first($id, $name) ?? '')) ?: '#' . $id;
        }
        natcasesort($out);

        return $out;
    }

    /**
     * A label created (in «Etiketten» or one of its categories) or changed, and the rolls that allow it from now on; one write.
     *
     * @param array<string, mixed> $given name, key, width, height, roll, copies, layout — only what changes
     * @param list<int>|null       $rolls exactly the rolls that allow it from now on; null: unchanged
     */
    public function save(int $label, int $node, array $given, ?array $rolls = null): int
    {
        $records = $this->records();
        $changes = new LabelChanges($this->structure());
        if ($label > 0) {
            if (! isset($records['node_of'][$label])) {
                throw LabelError::notALabel('#' . $label);
            }
            $list = $changes->update($label, $given, $rolls, $this->rollTable());
            if ($list !== []) {
                $this->door->write($list);
            }
            $this->forget();

            return $label;
        }
        if ($node !== $records['root'] && ! isset($records['categories'][$node])) {
            throw LabelError::notALabel('node ' . $node);
        }
        $answer = $this->door->write($changes->create($node, $given, $rolls ?? [], $this->rollTable()));
        $this->forget();

        return (int) ($answer['refs']['etikett'] ?? 0);
    }

    /** A label removed, and out of every roll that allowed it; refused while something else prints with it. */
    public function remove(int $label): void
    {
        if (! isset($this->records()['node_of'][$label])) {
            throw LabelError::notALabel('#' . $label);
        }
        $this->door->write((new LabelChanges($this->structure()))->remove($label, $this->rollTable(), $this->usersOf($label)));
        $this->forget();
    }

    /** @return list<string> what prints with this label elsewhere, as the module that owns it answers */
    public function usersOf(int $label): array
    {
        return $this->users()[$label] ?? [];
    }

    /** @return array<int, list<string>> label id ⇒ what prints with it elsewhere */
    public function users(): array
    {
        if ($this->users !== null) {
            return $this->users;
        }
        $answer = apply_filters(self::USERS_FILTER, []);
        $out    = [];
        foreach (is_array($answer) ? $answer : [] as $label => $names) {
            $out[(int) $label] = array_values(array_map('strval', (array) $names));
        }

        return $this->users = $out;
    }

    /** D-1192: the printer now holds that roll; one write, undone by changing back. */
    public function changeRoll(int $printer, int $roll): void
    {
        $s = $this->structure();
        if (! $s->hasNode('label_printer') || ! $s->hasField('printer_loaded_roll') || ! $this->table('label_printer')->has($printer)) {
            throw LabelError::notAPrinter('#' . $printer);
        }
        if (! isset($this->names('label_roll')[$roll])) {
            throw LabelError::notARoll('#' . $roll);
        }
        $this->door->write([['op' => 'set_values', 'record' => (string) $printer, 'values' => [(string) $s->field('printer_loaded_roll') => (string) $roll]]]);
        $this->forget();
    }

    /**
     * The pictures of these media library ids, in one read of the attachments (CD-7); what is no JPEG or PNG stays out.
     *
     * @param list<int> $ids
     * @return array<int, Picture>
     */
    public static function pictures(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        _prime_post_caches($ids, false, true);
        $out = [];
        foreach ($ids as $id) {
            $file = get_attached_file($id);
            if (! is_string($file) || ! is_readable($file) || filesize($file) > 4 * 1024 * 1024) {
                continue;
            }
            try {
                $out[$id] = Picture::fromBytes((string) file_get_contents($file));
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $out;
    }

    /**
     * D-1160, D-1295: the record's own words for these fields, as the modeller words them.
     *
     * @param list<string> $fields
     * @return array{values: array<string, string>, unknown: list<string>, node: int}
     */
    public function recordValues(int $record, array $fields): array
    {
        if ($record <= 0 || $fields === []) {
            return ['values' => [], 'unknown' => [], 'node' => 0];
        }
        $answer = $this->door->labelValues($record, $fields);

        return [
            'values'  => array_map('strval', (array) ($answer['values'] ?? [])),
            'unknown' => array_values(array_map('strval', (array) ($answer['unknown'] ?? []))),
            'node'    => (int) ($answer['node'] ?? 0),
        ];
    }

    private function forget(): void
    {
        $this->records = null;
        $this->catalog = null;
        $this->tables  = [];
        $this->users   = null;
    }
}
