<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\Core\Exception\DomainError;
use Taxmod\WordPress\ModelDoor;
use TaxmodLabel\Core\Drawing\Picture;
use TaxmodLabel\Core\LabelCatalog;
use TaxmodLabel\Core\LabelChanges;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\PrinterMedia;
use TaxmodLabel\Core\Structure;
use TaxmodLabel\Core\Table;
use TaxmodLabel\Core\Workplace;

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

    /**
     * `apply_filters(CATALOG_FILTER, null)`: the labels with their printers, for a module that offers them (D-1296: the stock
     * asks here instead of keeping its own copy). Null while the structure or the model has no labels node.
     */
    public const CATALOG_FILTER = 'taxmod_label_catalog';

    /** The answer to {@see CATALOG_FILTER}: an earlier answer stands; a model without labels leaves it as it was. */
    public static function answerCatalog(mixed $catalog): mixed
    {
        if ($catalog instanceof LabelCatalog) {
            return $catalog;
        }
        try {
            return (new self())->dialogCatalog();
        } catch (LabelError | DomainError) {
            // A model without the labels node (a fresh or foreign database): no labels, the page goes on (Prüftor 2026-11-20).
            return $catalog;
        }
    }

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
        $s        = $this->structure();
        $printers = $s->hasNode('label_printer') ? $this->table('label_printer') : null;
        // D-1333: the pause is a unit value of its own; all of them read in one go (CD-7).
        $parts    = $printers === null || ! $s->hasField('printer_pause') ? [] : array_values(array_filter(array_map(
            static fn (int $id): int => (int) $printers->first($id, $s->field('printer_pause')),
            $printers->ids()
        )));

        return $this->catalog = LabelCatalog::from(
            $s,
            $table,
            array_map(static fn (int $node): string => $categories[$node] ?? '', $nodeOf),
            $nodeOf,
            $printers,
            $this->rollTable(),
            $parts === [] ? null : new Table($this->door->valuesOf($parts)),
        );
    }

    /** D-1502: user meta holding only the record id of the person's workplace. */
    public const WORKPLACE_META = 'taxmod_label_workplace';

    /** D-1502: the workplace the current person chose; null without one, or where the record is gone or no workplace. */
    public function workplace(?int $userId = null): ?Workplace
    {
        $id = (int) get_user_meta($userId ?? get_current_user_id(), self::WORKPLACE_META, true);

        return $id > 0 ? $this->workplaceRecord($id) : null;
    }

    /** D-1502: one record of «Arbeitsplätze», for the list of workplaces. */
    public function workplaceRecord(int $id): ?Workplace
    {
        $places = $this->workplaces();

        return $places === null ? null : Workplace::from($this->structure(), $places, $id);
    }

    /**
     * D-1502: the records of «Arbeitsplätze»; null where the model has none — no role, or a role pointing at a node this
     * database does not have (Prüftor 2026-11-20: a dead reference never takes the page down, it is «kein Arbeitsplatz»).
     */
    public function workplaces(): ?Table
    {
        if (! $this->structure()->hasNode('label_workplace')) {
            return null;
        }
        try {
            return $this->table('label_workplace');
        } catch (DomainError) {
            return null;
        }
    }

    /** @return array<int, string> D-1502: workplace id ⇒ name, by name; empty where the model has no workplaces */
    public function workplaceNames(): array
    {
        return $this->workplaces() === null ? [] : $this->names('label_workplace');
    }

    /** D-1502: a new workplace with its name, one act (undo per act, D-1410); its printers are set in the record editor. */
    public function createWorkplace(string $name): int
    {
        $answer = $this->door->write([
            ['op' => 'create_record', 'ref' => 'platz', 'node' => (string) $this->structure()->node('label_workplace')],
            ['op' => 'set_values', 'record' => '@platz', 'values' => [(string) $this->structure()->field('label') => $name]],
        ]);

        return (int) ($answer['refs']['platz'] ?? 0);
    }

    /** D-1502: the labels as a print dialog offers them — only the printers of the person's workplace; without one, all. */
    public function dialogCatalog(): LabelCatalog
    {
        return $this->catalog()->at($this->workplace());
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

    /** D-1441: the media of every printer; null while the model has no «Druckmedien» yet. */
    public function media(): ?PrinterMedia
    {
        $s = $this->structure();

        return $s->hasNode('label_printer') && $s->hasField('printer_media') ? PrinterMedia::from($this->table('label_printer'), $s->field('printer_media')) : null;
    }

    /**
     * D-1441: the kind of every medium in the modeller's own words (D-1291); empty while the model has no «Art».
     *
     * @return array<int, string> medium id ⇒ kind
     */
    public function mediumKinds(): array
    {
        $s     = $this->structure();
        $rolls = array_keys($this->names('label_roll'));
        if ($rolls === [] || ! $s->hasField('medium_kind')) {
            return [];
        }

        return $this->door->fieldWords($rolls, [$s->field('medium_kind')])[$s->field('medium_kind')] ?? [];
    }

    /** D-1231: the printer on or off; one write, one change number for Undo (D-1410). */
    public function switchPrinter(int $printer, bool $on): void
    {
        $s = $this->structure();
        if (! $s->hasNode('label_printer') || ! $s->hasField('printer_active') || ! $this->table('label_printer')->has($printer)) {
            throw LabelError::notAPrinter('#' . $printer);
        }
        $this->door->write([['op' => 'set_values', 'record' => (string) $printer, 'values' => [(string) $s->field('printer_active') => $on ? '1' : '0']]]);
        $this->forget();
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
        $media = $this->media();
        if ($media !== null && ! $media->allows($printer, $roll)) {
            throw LabelError::notAMediumOf($this->names('label_printer')[$printer] ?? '#' . $printer);
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
