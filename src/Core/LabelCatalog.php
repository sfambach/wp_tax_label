<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * The labels kept as records (D-1153, D-1157, D-1159): each with its size, its roll, how many to print and its layout as JSON —
 * read once, handed to the modeller's label dialog as it is.
 *
 * ⚠️ *His word 2026-11-04: «Eigentlich hätte ich erwartet, dass wir einfach nur Etiketten haben» — one record per label; the
 * separate format records are gone (D-1159).*
 *
 * ```mermaid
 * flowchart LR
 *   PA["Platzart"] -- "Etikett" --> E["Etikett: Druckformat, Breite, Höhe, Rolle, Anzahl, Layout, Druckoptionen"]
 *   E -. "Rolle (D-1191)" .-> D["Drucker: Warteschlange, Rolle, Drehen, Optionen, Seitengrößen, Versatz"]
 * ```
 *
 * @see docs/konzept.md §2
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1159, D-1292
 */
final class LabelCatalog
{
    /**
     * @param array<int, array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string, node: int}> $templates
     */
    /**
     * @param array<int, array<string, mixed>> $printers D-1192: printer id ⇒ what the label dialog takes, with `allows` (label ids)
     */
    public function __construct(
        private readonly array $templates = [],
        private readonly array $printers = [],
        private readonly ?PrintRules $rules = null,
        private readonly int $workplace = 0,
    )
    {
    }

    /**
     * @param array<int, string> $categoryOf record id → the name of the category (sub-node of «Etiketten») it stands in
     * @param array<int, int>    $nodeOf     record id → the node it stands in
     * @param Table|null         $printers   D-1191: the records of «Drucker», read once beside the labels
     * @param Table|null         $rolls      D-1192: the records of «Rollen» — a printer holds one, a roll allows its labels
     * @param Table|null         $pauses     D-1333: the unit values a printer's pause points at, read once beside the printers
     */
    public static function from(Structure $s, Table $labels, array $categoryOf = [], array $nodeOf = [], ?Table $printers = null, ?Table $rolls = null, ?Table $pauses = null): self
    {
        $int  = static fn (int $id, string $field): int => $s->hasField($field) ? (int) $labels->first($id, $s->field($field)) : 0;
        $text = static fn (int $id, string $field): string => $s->hasField($field) ? trim((string) $labels->first($id, $s->field($field))) : '';
        $out  = [];
        $all    = self::printers($s, $printers, $rolls, $pauses);

        foreach ($labels->ids() as $id) {
            $key    = $text($id, 'template_key');
            $layout = $text($id, 'template_layout');

            // A label without a size or a layout cannot print; it stays out rather than drawing an empty one.
            if ($key === '' || $layout === '' || $int($id, 'template_width') <= 0 || $int($id, 'template_height') <= 0) {
                continue;
            }

            $out[$id] = [
                'name'   => (string) ($labels->first($id, $s->field('label')) ?? '#' . $id),
                'format' => ['key' => $key, 'w' => $int($id, 'template_width'), 'h' => $int($id, 'template_height'), 'roll' => $int($id, 'template_roll')],
                'copies' => max(1, $int($id, 'template_copies')),
                'layout' => $layout,
                'category' => $categoryOf[$id] ?? '',
                'node'     => $nodeOf[$id] ?? 0,
            ];
            // D-1192: the first printer (by name) whose loaded roll allows this label is the one the dialog starts with.
            foreach ($all as $printer) {
                if (in_array($id, $printer['allows'], true)) {
                    $out[$id]['printer'] = array_diff_key($printer, ['allows' => 1, 'roll_name' => 1]);
                    break;
                }
            }
        }

        return new self($out, $all);
    }

    /**
     * D-1191, D-1192: every printer with a queue and a loaded roll, by name — its settings as the modeller's label dialog takes
     * them, the width of the roll it holds, and the labels that roll allows.
     *
     * @return array<int, array{queue: string, roll: int, turn: bool, options: string, turn_options: string, sizes: string, offset: int, line: int, retry: bool, dpi: int, pause: int, name: string, roll_name: string, allows: list<int>}>
     */
    private static function printers(Structure $s, ?Table $printers, ?Table $rolls, ?Table $pauses = null): array
    {
        if ($printers === null || $rolls === null || ! $s->hasField('printer_queue') || ! $s->hasField('printer_loaded_roll') || ! $s->hasField('roll_labels')) {
            return [];
        }
        $text = static fn (Table $t, int $id, string $field): string => $s->hasField($field) ? trim((string) $t->first($id, $s->field($field))) : '';
        $out  = [];
        foreach ($printers->ids() as $id) {
            $queue = $text($printers, $id, 'printer_queue');
            $roll  = (int) $text($printers, $id, 'printer_loaded_roll');
            // D-1231: his wish 2026-11-08 «Drucker deaktivieren» — a printer switched off is not offered.
            if ($queue === '' || $roll <= 0 || ! $rolls->has($roll) || ! LabelPrinter::isOn($text($printers, $id, 'printer_active'))) {
                continue;
            }
            $out[$id] = [
                'queue'        => $queue,
                'roll'         => (int) $text($rolls, $roll, 'roll_width'),
                'turn'         => $text($printers, $id, 'printer_turns') === '1',
                'options'      => $text($printers, $id, 'printer_options'),
                'turn_options' => $text($printers, $id, 'printer_turn_options'),
                'sizes'        => $text($printers, $id, 'printer_sizes'),
                'offset'       => (int) $text($printers, $id, 'printer_offset'),
                'line'         => (int) $text($printers, $id, 'printer_line'),
                'retry'        => $text($printers, $id, 'printer_retry') === '1',
                'dpi'          => (int) $text($printers, $id, 'printer_dpi'),
                'pause'        => self::pause($s, $pauses, (int) $text($printers, $id, 'printer_pause')),
                'name'         => trim((string) ($printers->first($id, $s->field('label')) ?? '')),
                'roll_name'    => trim((string) ($rolls->first($roll, $s->field('label')) ?? '')),
                'allows'       => array_values(array_map('intval', $rolls->all($roll, $s->field('roll_labels')))),
            ];
        }
        uasort($out, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** D-1333: the seconds a printer's pause holds — a unit value in seconds; none is 0. */
    private static function pause(Structure $s, ?Table $pauses, int $part): int
    {
        if ($part <= 0 || $pauses === null || ! $s->hasField('money_amount')) {
            return 0;
        }

        return max(0, (int) round((float) $pauses->first($part, $s->field('money_amount'))));
    }

    /**
     * D-1192: the label dialog for a label of this category — the chosen label first, the others of its category to choose from,
     * and every printer with the labels its loaded roll allows; `$more` (record, values) goes with each label.
     *
     * D-1199: `$contents` holds the names of what lies on the place; each label takes the ones its choice asks for.
     *
     * @param array<string, mixed> $more
     * @param array{categories?: list<string>, materials?: list<string>, words?: array<string, string>} $contents
     * @return array<string, mixed>
     */
    public function dialog(int $chosen, array $more = [], array $contents = []): array
    {
        $template = $this->templates[$chosen] ?? null;
        if ($template === null) {
            return [];
        }
        $choices = [];
        foreach ($this->inNode((int) ($template['node'] ?? 0)) ?: [$chosen] as $id) {
            $choices[$id] = self::forButton(array_diff_key($this->templates[$id], ['printer' => 1]), self::withContents($this->templates[$id], $more, $contents));
        }

        return self::forButton($template, self::withContents($template, $more, $contents)) + ['choices' => $choices, 'chosen' => $chosen, 'printers' => $this->printers];
    }

    /**
     * D-1199, his choice «Auswahl im Druckdialog»: a label whose layout names `inhalt` offers what to print there — nothing, the
     * categories or the materials — each shortened to what its layout holds; the dialog starts on the categories.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $more
     * @param array{categories?: list<string>, materials?: list<string>, words?: array<string, string>} $contents
     * @return array<string, mixed>
     */
    private static function withContents(array $template, array $more, array $contents): array
    {
        $layout = (string) ($template['layout'] ?? '');
        if (! isset($contents['categories']) || ! LabelContents::named($layout)) {
            return $more;
        }
        $max   = LabelContents::maxOf($layout);
        $words = $contents['words'] ?? [];
        $more2 = (string) ($words['more'] ?? '+%d');

        return $more + ['variants' => [
            'field'   => LabelContents::FIELD,
            'label'   => (string) ($words['label'] ?? LabelContents::FIELD),
            'chosen'  => LabelContents::CATEGORIES,
            'options' => [
                LabelContents::NOTHING    => ['name' => (string) ($words[LabelContents::NOTHING] ?? '—'), 'text' => ''],
                LabelContents::CATEGORIES => ['name' => (string) ($words[LabelContents::CATEGORIES] ?? LabelContents::CATEGORIES), 'text' => LabelContents::line($contents['categories'] ?? [], $max, $more2)],
                LabelContents::MATERIALS  => ['name' => (string) ($words[LabelContents::MATERIALS] ?? LabelContents::MATERIALS), 'text' => LabelContents::line($contents['materials'] ?? [], $max, $more2)],
            ],
        ]];
    }

    /** @return list<int> the labels of one category, by name; none for 0 */
    public function inNode(int $node): array
    {
        $names = [];
        foreach ($this->templates as $id => $template) {
            if ($node > 0 && ($template['node'] ?? 0) === $node) {
                $names[$id] = $template['name'];
            }
        }
        natcasesort($names);

        return array_keys($names);
    }

    /**
     * D-1502: the labels as one sees them at a workplace — only its printers, and each label starting on the workplace's
     * default printer where that one's roll allows it, else on the first of its printers that does. Without a workplace, all.
     */
    public function at(?Workplace $place): self
    {
        if ($place === null) {
            return $this;
        }
        $printers = array_intersect_key($this->printers, array_flip($place->printers));
        // The default printer first, the others by name as before.
        if (isset($printers[$place->defaultPrinter])) {
            $printers = [$place->defaultPrinter => $printers[$place->defaultPrinter]] + $printers;
        }

        return $this->onPrinters($printers, $place->id);
    }

    /** D-1519: the print rules this catalog answers with — read once, asked for every record. */
    public function withRules(PrintRules $rules): self
    {
        return new self($this->templates, $this->printers, $rules, $this->workplace);
    }

    /**
     * D-1519: the first print rule that holds for what is about to be printed — and can print here: its label known, its printer
     * (where it names one) among this catalog's printers, with a roll that allows the label. Null: no rule, the way of today.
     *
     * @param array<string, mixed> $asked see {@see PrintContext::fromArray()}
     * @return array{id: int, name: string, label: int, copies: ?int, printer: int}|null
     */
    public function ruleFor(array $asked): ?array
    {
        $rule = $this->rules?->first(PrintContext::fromArray($asked, $this->workplace), fn (PrintRule $r): bool => isset($this->templates[$r->label])
            && ($r->printer === 0 || in_array($r->label, $this->printers[$r->printer]['allows'] ?? [], true)));

        return $rule === null ? null : ['id' => $rule->id, 'name' => $rule->name, 'label' => $rule->label, 'copies' => $rule->copies, 'printer' => $rule->printer];
    }

    /** D-1519: the same labels with `$printer` first — the printer a rule names; unchanged where it is not one of these. */
    public function preferring(int $printer): self
    {
        if (! isset($this->printers[$printer])) {
            return $this;
        }

        return $this->onPrinters([$printer => $this->printers[$printer]] + $this->printers, $this->workplace);
    }

    /** Each label starts on the first of `$printers` whose roll allows it. */
    private function onPrinters(array $printers, int $workplace): self
    {
        $templates = [];
        foreach ($this->templates as $id => $template) {
            unset($template['printer']);
            foreach ($printers as $printer) {
                if (in_array($id, $printer['allows'], true)) {
                    $template['printer'] = array_diff_key($printer, ['allows' => 1, 'roll_name' => 1]);
                    break;
                }
            }
            $templates[$id] = $template;
        }

        return new self($templates, $printers, $this->rules, $workplace);
    }

    /** @return array<int, array<string, mixed>> D-1192: the printers, for the list under Druck › Drucker */
    public function printerList(): array
    {
        return $this->printers;
    }

    /**
     * What the modeller's label button takes of a label (D-1153, D-1191): name, size, layout and, where one holds its roll, its printer.
     *
     * @param array{name: string, format: array{key: string, w: int, h: int, roll: int}, layout: string, printer?: array<string, mixed>} $template
     * @param array<string, mixed> $more record, values …
     * @return array<string, mixed>
     */
    public static function forButton(array $template, array $more = []): array
    {
        return ['name' => $template['name'], 'format' => $template['format'], 'layout' => $template['layout']]
            + (isset($template['printer']) ? ['printer' => $template['printer']] : []) + $more;
    }

    /**
     * D-1160: the first label (by name) of a category; 0 where it holds none that can print. D-1192: a label some printer's loaded
     * roll allows goes before one none does.
     */
    public function firstIn(int $node): int
    {
        $ids = $this->inNode($node);
        foreach ($ids as $id) {
            if (isset($this->templates[$id]['printer'])) {
                return $id;
            }
        }

        return $ids[0] ?? 0;
    }

    /**
     * D-1266: the label a group asks for, when it is one of this category that some printer's roll allows; else as before,
     * the first of the category ({@see firstIn()}).
     */
    public function preferredIn(int $node, int $wanted): int
    {
        return $wanted > 0 && ($this->templates[$wanted]['node'] ?? 0) === $node && isset($this->templates[$wanted]['printer']) ? $wanted : $this->firstIn($node);
    }

    public function has(int $id): bool
    {
        return isset($this->templates[$id]);
    }

    /** @return array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string, node: int}|null */
    public function get(int $id): ?array
    {
        return $this->templates[$id] ?? null;
    }

    /** @return array<int, array{name: string, format: array{key: string, w: int, h: int, roll: int}, copies: int, layout: string, category: string, node: int}> */
    public function all(): array
    {
        return $this->templates;
    }
}
