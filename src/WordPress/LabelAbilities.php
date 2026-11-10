<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\WordPress\Plugin as Modeller;
use Taxmod\WordPress\Printing\PrintService;
use TaxmodLabel\Core\FilledLabel;
use TaxmodLabel\Core\LabelChanges;
use TaxmodLabel\Core\LabelContent;
use TaxmodLabel\Core\LabelDocument;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\LabelLayout;
use TaxmodLabel\Core\LabelProblems;
use TaxmodLabel\Core\Problem;

/**
 * The labels as abilities (D-1239, moved here by D-1292): read, create or change, remove, look at the drawing, and print.
 *
 * ```mermaid
 * flowchart LR
 *   R["taxmod-label/labels"] --> E["label records (layout = JSON)"]
 *   S["taxmod-label/label-save (dry_run, pdf)"] -- "one change list" --> E
 *   D["taxmod-label/label-remove"] -- "out of the rolls, then the record" --> E
 *   V["taxmod-label/preview"] --> W["SVG or PDF + problems"]
 *   P["taxmod-label/print"] -- "ModelDoor::queuePrint" --> J["print jobs (modeller)"]
 * ```
 *
 * @see docs/konzept.md §7
 */
final class LabelAbilities
{
    public const READ    = 'taxmod-label/labels';
    public const SAVE    = 'taxmod-label/label-save';
    public const REMOVE  = 'taxmod-label/label-remove';
    public const PREVIEW = 'taxmod-label/preview';
    public const PRINT   = 'taxmod-label/print';

    private const CATEGORY = 'taxmod';

    public function register(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }
        $ids     = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]];
        $allowed = static fn (): bool => current_user_can(Modeller::CAPABILITY);
        $ability = fn (string $name, string $label, string $description, array $properties, \Closure $act, bool $readonly, bool $destructive = false, array $required = []): bool => (bool) wp_register_ability($name, [
            'label'               => $label,
            'description'         => $description,
            'category'            => self::CATEGORY,
            'input_schema'        => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false] + ($required === [] ? [] : ['required' => $required]),
            'execute_callback'    => fn (mixed $input = null): array|\WP_Error => self::guarded(fn (): array => $act(is_array($input) ? $input : [])),
            'permission_callback' => $allowed,
            'meta'                => ['public' => true, 'annotations' => ['readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $readonly]],
        ]);

        $ability(self::READ, __('Read the labels', 'taxmod-label'), __('Every label record: id, name, category, size key, width, height, roll, copies, complete (prints), the rolls that allow it, the printers holding such a roll. ids: only these, with their layout. layout: true adds the layout JSON to every label. Also the categories (node ids for label-save), the rolls and the printers.', 'taxmod-label'), [
            'ids' => $ids, 'layout' => ['type' => 'boolean', 'default' => false],
        ], $this->read(...), true);

        $ability(self::SAVE, __('Create or change a label', 'taxmod-label'), __('With id: changes only the fields given. Without id: a new label in category (node id from taxmod-label/labels), name needed. Fields: name, key (size key, e.g. 40x12), width, height (mm), roll (mm), copies, layout (JSON: a list of elements or {"label":{…},"elements":[…]}, as string or array; checked before anything is written — every problem is answered). rolls: roll ids that allow the label from now on. dry_run: checks and answers, writes nothing. pdf: true answers the label drawn with the examples of its layout as base64 PDF.', 'taxmod-label'), [
            'id' => ['type' => 'integer', 'minimum' => 1], 'category' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string'],
            'key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_\-]+$'], 'width' => ['type' => 'integer', 'minimum' => 1], 'height' => ['type' => 'integer', 'minimum' => 1],
            'roll' => ['type' => 'integer', 'minimum' => 1], 'copies' => ['type' => 'integer', 'minimum' => 1], 'layout' => ['type' => ['string', 'array', 'object']],
            'rolls' => $ids, 'dry_run' => ['type' => 'boolean', 'default' => false], 'pdf' => ['type' => 'boolean', 'default' => false],
        ], $this->save(...), false);

        $ability(self::REMOVE, __('Remove a label', 'taxmod-label'), __('Removes the label record and takes it out of every roll that allowed it; refused while something else prints with it (a place type of the stock). Answers the label as it was (layout included), so label-save can make it again. dry_run: answers, removes nothing.', 'taxmod-label'), [
            'id' => ['type' => 'integer', 'minimum' => 1], 'dry_run' => ['type' => 'boolean', 'default' => false],
        ], $this->remove(...), false, true, ['id']);

        $ability(self::PREVIEW, __('Draw a label', 'taxmod-label'), __('Draws a layout (or the label id) as the printer would: svg (default) or pdf (base64), with the examples of the layout or the given values, at the dpi given (default 203). Answers every problem — a code too small to scan, a value a code cannot take, a box past the edge — with its element.', 'taxmod-label'), [
            'id' => ['type' => 'integer', 'minimum' => 1], 'layout' => ['type' => ['string', 'array', 'object']], 'width' => ['type' => 'number', 'minimum' => 1], 'height' => ['type' => 'number', 'minimum' => 1],
            'values' => ['type' => 'object'], 'dpi' => ['type' => 'number', 'minimum' => 50, 'maximum' => 2400], 'as' => ['type' => 'string', 'enum' => ['svg', 'pdf'], 'default' => 'svg'],
        ], $this->preview(...), true);

        $ability(self::PRINT, __('Print a label', 'taxmod-label'), __('Queues one label for the print service, built as the label dialog builds it: code (M-<material>, P-<lot>, O-/K-<place>), label (record id of a label), printer (record id; empty: the first whose roll allows the label), copies. dry_run: true answers what would print and queues nothing. pdf: true adds the label as base64 PDF. Returns the job id; follow it with taxmod/print-jobs.', 'taxmod-label'), [
            'code' => ['type' => 'string', 'pattern' => '^[A-Za-z]-[0-9]+$'], 'label' => ['type' => 'integer', 'minimum' => 1], 'printer' => ['type' => 'integer', 'minimum' => 1],
            'copies' => ['type' => 'integer', 'minimum' => 1, 'maximum' => LabelButton::MAX_COPIES, 'default' => 1], 'dry_run' => ['type' => 'boolean', 'default' => false], 'pdf' => ['type' => 'boolean', 'default' => false],
        ], $this->print(...), false, false, ['code', 'label']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function read(array $input): array
    {
        $service = new LabelService();
        $only    = array_values(array_filter(array_map('intval', (array) ($input['ids'] ?? [])), static fn (int $id): bool => $id > 0));
        $all     = $this->labels($service, $only !== [] || ! empty($input['layout']));
        $labels  = $only === [] ? array_values($all) : array_values(array_intersect_key($all, array_flip($only)));
        ['categories' => $categories, 'root' => $root] = $service->records();
        $s        = $service->structure();
        $rollRows = $service->rollTable();
        $rolls    = [];
        foreach ($service->names('label_roll') as $id => $name) {
            $rolls[] = ['id' => $id, 'name' => $name, 'width' => $rollRows !== null && $s->hasField('roll_width') ? (int) $rollRows->first($id, $s->field('roll_width')) : 0];
        }
        $printers = [];
        foreach ($service->catalog()->printerList() as $id => $printer) {
            $printers[] = ['id' => $id, 'name' => $printer['name'], 'queue' => $printer['queue'], 'roll' => $printer['roll_name'], 'dpi' => $printer['dpi'] ?: null];
        }

        return [
            'labels'     => $labels,
            'categories' => array_merge([['node' => $root, 'name' => '']], array_map(static fn (int $node, string $name): array => ['node' => $node, 'name' => $name], array_keys($categories), $categories)),
            'rolls'      => $rolls,
            'printers'   => $printers,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $service = new LabelService();
        $id      = absint($input['id'] ?? 0);
        $given   = self::given($input);
        $rolls   = array_values(array_filter(array_map('intval', (array) ($input['rolls'] ?? [])), static fn (int $r): bool => $r > 0));
        (new LabelChanges($service->structure()))->values($given);

        $before   = $id > 0 ? ($this->labels($service, true)[$id] ?? throw LabelError::notALabel('#' . $id)) : [];
        $after    = array_merge(['name' => '', 'key' => '', 'width' => 0, 'height' => 0, 'roll' => 0, 'copies' => 1, 'layout' => ''], $before, $given);
        $problems = self::problems($after);
        if (Problem::hasError($problems)) {
            throw new LabelProblems(array_values(array_filter($problems, static fn (Problem $p): bool => $p->error)));
        }
        $filled = self::filled($after);
        $pdf    = ! empty($input['pdf']) && $filled !== null ? ['pdf' => base64_encode($filled->render(1, true, null, LabelService::pictures($filled->layout->media())))] : [];
        $warn   = ['warnings' => ProblemWords::list($problems)];

        if (! empty($input['dry_run'])) {
            return ['id' => $id, 'written' => false, 'label' => array_diff_key($after, ['rolls' => 1, 'printers' => 1]) + ['complete' => $filled !== null], 'before' => $before] + $warn + $pdf;
        }
        $saved = $service->save($id, absint($input['category'] ?? 0), $given, $rolls);

        return ['id' => $saved, 'written' => true, 'label' => $this->labels(new LabelService(), true)[$saved] ?? [], 'before' => $before] + $warn + $pdf;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function remove(array $input): array
    {
        $service = new LabelService();
        $id      = absint($input['id'] ?? 0);
        $label   = $this->labels($service, true)[$id] ?? throw LabelError::notALabel('#' . $id);
        $users   = $service->usersOf($id);
        if ($users !== []) {
            throw LabelError::inUse(implode(', ', $users));
        }
        if (empty($input['dry_run'])) {
            $service->remove($id);
        }

        return ['id' => $id, 'removed' => empty($input['dry_run']), 'label' => $label];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array
    {
        $label = [];
        if (absint($input['id'] ?? 0) > 0) {
            $label = $this->labels(new LabelService(), true)[absint($input['id'])] ?? throw LabelError::notALabel('#' . absint($input['id']));
        }
        $layout = $input['layout'] ?? ($label['layout'] ?? '');
        $json   = is_string($layout) ? $layout : (string) wp_json_encode($layout, JSON_UNESCAPED_UNICODE);

        return Preview::draw($json, (float) ($input['width'] ?? $label['width'] ?? 0), (float) ($input['height'] ?? $label['height'] ?? 0), is_array($input['values'] ?? null) ? $input['values'] : null, (float) ($input['dpi'] ?? 0), ($input['as'] ?? 'svg') === 'pdf');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function print(array $input): array
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if (! LabelPrefix::isCode($code)) {
            throw LabelError::notALabel($code);
        }
        [$prefix, $id] = explode('-', $code, 2);
        $template = absint($input['label'] ?? 0);
        $printer  = absint($input['printer'] ?? 0);
        $copies   = LabelButton::copies((int) ($input['copies'] ?? 1));
        // D-1235: the module that owns the code builds the label as its dialog does (the stock: materials and lots).
        $found = apply_filters('taxmod_label_for', null, $prefix, (int) $id, $template, $printer);
        if (! is_array($found) || ! is_array($found['template'] ?? null)) {
            throw LabelError::notALabel($code . ' / #' . $template);
        }
        $label  = new LabelContent($code, (string) ($found['title'] ?? $code), (string) ($found['subtitle'] ?? ''));
        $filled = LabelButton::filled($label, LabelButton::expanded($found['template']));
        if ($filled === null) {
            throw LabelError::notALabel('#' . $template);
        }
        $pictures = LabelService::pictures($filled->layout->media());
        $answer   = ['code' => $code, 'title' => $label->title, 'label' => $filled->templateName, 'queue' => $filled->printer?->queue ?? '', 'copies' => $copies, 'values' => $filled->values];
        if (! empty($input['pdf'])) {
            $answer['pdf'] = base64_encode($filled->render(1, true, null, $pictures));
        }
        if (! empty($input['dry_run'])) {
            return $answer + ['job' => 0];
        }
        if (! class_exists(PrintService::class) || ! PrintService::isSetUp()) {
            throw new \RuntimeException(__('The print service is not set up.', 'taxmod-label'));
        }

        return $answer + ['job' => LabelButton::queue($filled, $label, $copies, $pictures)];
    }

    /**
     * Every label record, complete or not, as the abilities answer it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function labels(LabelService $service, bool $withLayout): array
    {
        $s = $service->structure();
        ['table' => $table, 'categories' => $categories, 'node_of' => $nodeOf] = $service->records();
        $text    = static fn (int $id, string $key): string => $s->hasField($key) ? trim((string) $table->first($id, $s->field($key))) : '';
        $catalog = $service->catalog();
        $changes = new LabelChanges($s);
        $rolls   = $service->rollTable();
        $out     = [];
        foreach ($table->ids() as $id) {
            $printers = [];
            foreach ($catalog->printerList() as $printer) {
                if (in_array($id, $printer['allows'], true)) {
                    $printers[] = $printer['name'];
                }
            }
            $out[$id] = [
                'id'       => $id,
                'name'     => $text($id, 'label'),
                'category' => $categories[$nodeOf[$id] ?? 0] ?? '',
                'node'     => $nodeOf[$id] ?? 0,
                'key'      => $text($id, 'template_key'),
                'width'    => (int) $text($id, 'template_width'),
                'height'   => (int) $text($id, 'template_height'),
                'roll'     => (int) $text($id, 'template_roll'),
                'copies'   => max(1, (int) $text($id, 'template_copies')),
                'complete' => $catalog->has($id),
                'rolls'    => $changes->rollsAllowing($id, $rolls),
                'printers' => $printers,
            ] + ($withLayout ? ['layout' => $text($id, 'template_layout')] : []);
        }
        ksort($out);

        return $out;
    }

    /**
     * What a caller gave, the editor's document split up: its «label» settings go to the fields, its elements to the layout.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function given(array $input): array
    {
        $given = array_intersect_key($input, LabelChanges::FIELDS);
        if (! array_key_exists('layout', $given)) {
            return $given;
        }
        $layout = is_string($given['layout']) ? $given['layout'] : (string) wp_json_encode($given['layout'], JSON_UNESCAPED_UNICODE);
        [$parsed] = LabelLayout::parse($layout);
        if ($parsed === null) {
            $given['layout'] = $layout;

            return $given;
        }
        if ($parsed->settings !== null) {
            $given += ['width' => (int) round($parsed->settings->widthMm), 'height' => (int) round($parsed->settings->heightMm), 'copies' => $parsed->settings->copies]
                + ($parsed->settings->rollMm > 0 ? ['roll' => (int) round($parsed->settings->rollMm)] : []);
        }
        $given['layout'] = $parsed->toJson();

        return $given;
    }

    /**
     * Everything wrong with a label as it would be saved: its layout and the drawing with its examples at 203 dpi.
     *
     * @param array<string, mixed> $label
     * @return list<Problem>
     */
    public static function problems(array $label): array
    {
        if (trim((string) $label['layout']) === '') {
            return [];
        }
        [$layout, $problems] = LabelLayout::parse((string) $label['layout']);
        if ($layout === null || (int) $label['width'] <= 0 || (int) $label['height'] <= 0) {
            return $problems;
        }
        [, $drawn] = LabelDocument::svg((float) $label['width'], (float) $label['height'], $layout, $layout->examples() + ['code' => 'M-12345'], null, LabelService::pictures($layout->media()));

        return [...$problems, ...$drawn];
    }

    /**
     * The label drawn with the examples its layout carries, or null while it lacks a size or a layout.
     *
     * @param array<string, mixed> $label
     */
    private static function filled(array $label): ?FilledLabel
    {
        if (trim((string) $label['layout']) === '' || (int) $label['width'] <= 0 || (int) $label['height'] <= 0 || trim((string) $label['key']) === '') {
            return null;
        }
        $layout = LabelLayout::fromJson((string) $label['layout']);

        return FilledLabel::fromJson((string) wp_json_encode([
            'format'   => ['key' => (string) $label['key'], 'w' => (int) $label['width'], 'h' => (int) $label['height'], 'roll' => (int) $label['roll']],
            'layout'   => json_decode($layout->toJson(), true),
            'values'   => $layout->examples() + ['code' => 'M-12345'],
            'template' => (string) $label['name'],
        ]));
    }

    /**
     * Core refusals become WP_Error with the problems in the user's words (CD-10).
     *
     * @param \Closure(): array<string, mixed> $act
     * @return array<string, mixed>|\WP_Error
     */
    public static function guarded(\Closure $act): array|\WP_Error
    {
        try {
            return $act();
        } catch (LabelProblems $e) {
            return new \WP_Error('taxmod_label_problems', implode(' ', array_map(static fn (array $p): string => $p['text'], ProblemWords::list($e->problems))), ['status' => 400, 'problems' => ProblemWords::list($e->problems)]);
        } catch (LabelError $e) {
            return new \WP_Error('taxmod_label_' . $e->reason, ProblemWords::ofError($e), ['status' => 400]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new \WP_Error('taxmod_label_refused', $e->getMessage(), ['status' => 400]);
        }
    }
}
