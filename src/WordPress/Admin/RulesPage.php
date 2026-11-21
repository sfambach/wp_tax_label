<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress\Admin;

use Taxmod\Core\Description\InputKind;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Plugin as Modeller;
use TaxmodLabel\Core\PrintRule;
use TaxmodLabel\Core\PrintRuleChanges;
use TaxmodLabel\WordPress\LabelService;

/**
 * Einstellungen › Druck › Druckregeln (D-1519, his card 2026-11-20 17:39, R4): the rules in their order — the first that holds
 * chooses label, printer and count — each opened in the modeller's record editor, «Neue Regel», and «Vorwahlen übernehmen», which
 * shows what it would write before it writes it (R2).
 *
 * @see wp-taxonomy-modeler docs/pakete/druckregeln/konzept.md
 */
final class RulesPage
{
    public const SLUG = 'taxmod-label-rules';

    public const ACTION = 'taxmod_label_rules';

    public static function boot(): void
    {
        add_action('admin_post_' . self::ACTION, self::handle(...));
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(['page' => 'taxmod-lager-modul', 'module' => self::SLUG] + $args, admin_url('admin.php'));
    }

    /** admin-post «Neue Regel» and «Vorwahlen übernehmen»: capability → nonce → validate → sanitize → act (CD-5, D-1254). */
    public static function handle(): void
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            ListPage::fail(__('Keine Berechtigung.', 'taxmod-label'), self::url());
        }
        ListPage::nonce(self::ACTION);
        $do   = sanitize_key(wp_unslash((string) ($_POST['do'] ?? '')));
        $name = sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')));
        ListPage::act(static function () use ($do, $name): string {
            $service = new LabelService();
            if ($do === 'create') {
                if ($name === '') {
                    throw new \RuntimeException(__('Die Regel braucht einen Namen.', 'taxmod-label'));
                }

                return self::url(['satz' => $service->createRule($name)]);
            }
            if ($do === 'takeover') {
                return self::url(['uebernommen' => $service->takeOverRules()]);
            }
            throw new \RuntimeException(__('Unbekannte Aktion.', 'taxmod-label'));
        }, self::url());
    }

    public function render(): string
    {
        $service = new LabelService();
        $s       = $service->structure();
        if (! $s->hasNode('label_rule')) {
            return '<p>' . esc_html__('Im Modell gibt es noch keinen Knoten «Druckregeln».', 'taxmod-label') . '</p>';
        }
        $rules   = $service->rules()->all();
        $explain = __('Beim Drucken gilt die erste Regel, die passt: sie wählt das Etikett, wenn gesetzt auch Drucker und Anzahl. Leere Bedingungen passen immer. Passt keine, bleibt die Vorwahl wie bisher; ohne Drucker in der Regel beginnt der Dialog mit dem Standarddrucker des Arbeitsplatzes.', 'taxmod-label');
        $chosen  = absint($_GET['satz'] ?? 0);
        foreach ($rules as $rule) {
            if ($rule->id === $chosen) {
                return ListPage::recordHead(__('Druckregel', 'taxmod-label'), $rule->name === '' ? '#' . $rule->id : $rule->name, self::url(), __('Druckregeln', 'taxmod-label'), $explain)
                    . (string) apply_filters('taxmod_record_editor', '', $chosen, self::url(['satz' => $chosen]), [], self::roots($s));
            }
        }

        return (isset($_GET['uebernommen']) ? '<div class="notice notice-success inline"><p>' . esc_html(sprintf(/* translators: %d: number of rules */ _n('%d Vorwahl als Regel übernommen.', '%d Vorwahlen als Regeln übernommen.', absint($_GET['uebernommen']), 'taxmod-label'), absint($_GET['uebernommen']))) . '</p></div>' : '')
            . ListPage::listHead(__('Druckregeln', 'taxmod-label'), count($rules), $explain)
            . ListPage::listBar('', buttons: $this->buttons($service))
            . ListPage::table([[__('Nr.', 'taxmod-label'), '4em'], [__('Regel', 'taxmod-label'), '14em'], [__('Wenn', 'taxmod-label'), ''], [__('Etikett', 'taxmod-label'), '12em'], [__('Drucker', 'taxmod-label'), '10em'], [__('Anzahl', 'taxmod-label'), '5em'], ['', '4em']], $this->rows($service));
    }

    /**
     * D-1546, D-1548: the rule's node and its material group are chosen under the branches «Material steht in» names — asked of the
     * stock (`taxmod_material_roots`), never a wired branch.
     *
     * @return array<int, list<int>> relation id → roots
     */
    private static function roots(\TaxmodLabel\Core\Structure $s): array
    {
        $roots = array_values(array_filter(array_map('absint', (array) apply_filters('taxmod_material_roots', []))));
        $out   = [];
        foreach ($roots === [] ? [] : ['rule_node', 'rule_group'] as $key) {
            if ($s->hasField($key)) {
                $out[$s->field($key)] = $roots;
            }
        }

        return $out;
    }

    private function rows(LabelService $service): string
    {
        $rules = $service->rules()->all();
        if ($rules === []) {
            return '<tr class="no-items"><td class="colspanchange" colspan="8">' . esc_html__('Noch keine Regel: der Druckdialog wählt wie bisher.', 'taxmod-label') . '</td></tr>';
        }
        $s      = $service->structure();
        $fields = array_values(array_filter(array_map(static fn (string $key): int => $s->hasField($key) ? $s->field($key) : 0, ['rule_for', 'rule_node', 'rule_form', 'rule_group', 'rule_place_type', 'rule_workplace', 'rule_label', 'rule_printer'])));
        // D-1291: the words as the modeller shows them, one run for every rule (CD-7).
        $words  = (new ModelDoor())->fieldWords(array_map(static fn (PrintRule $rule): int => $rule->id, $rules), $fields);
        $door   = new ModelDoor();
        $named  = [];
        // The field names at the rule's node, read once per node (CD-7).
        $names  = static function (int $node) use ($door, &$named): array {
            return $node <= 0 ? [] : $named[$node] ??= array_map(static fn (array $field): string => $field['name'], $door->fields($node));
        };
        $word   = static fn (string $key, int $id): string => $s->hasField($key) ? trim((string) ($words[$s->field($key)][$id] ?? '')) : '';
        $body   = '';
        foreach ($rules as $rule) {
            $when = array_filter([
                $word('rule_for', $rule->id),
                ($w = $word('rule_node', $rule->id)) === '' ? '' : __('Knoten', 'taxmod-label') . ' ' . $w,
                ($w = $word('rule_form', $rule->id)) === '' ? '' : __('Bauform', 'taxmod-label') . ' ' . $w,
                ($w = $word('rule_group', $rule->id)) === '' ? '' : __('Gruppe', 'taxmod-label') . ' ' . $w,
                ($w = $word('rule_place_type', $rule->id)) === '' ? '' : __('Platzart', 'taxmod-label') . ' ' . $w,
                ($w = $word('rule_workplace', $rule->id)) === '' ? '' : __('Arbeitsplatz', 'taxmod-label') . ' ' . $w,
                // D-1548: the filters, as the test rules show them.
                ...array_map(static fn (array $f): string => sprintf('%s %s %s', $names($rule->node)[$f['field']] ?? '#' . $f['field'], $f['operator'] === \TaxmodLabel\Core\PrintRules::EQUALS ? '=' : '?', $f['value']), $rule->filters),
            ]);
            $body .= '<tr><th scope="row" class="check-column"></th><td>' . esc_html((string) $rule->position) . '</td>'
                . '<td><a href="' . esc_url(self::url(['satz' => $rule->id])) . '"><strong>' . esc_html($rule->name === '' ? '#' . $rule->id : $rule->name) . '</strong></a></td>'
                . '<td>' . esc_html(implode(' · ', $when) ?: __('immer', 'taxmod-label')) . '</td>'
                . '<td>' . esc_html($word('rule_label', $rule->id) ?: '—') . '</td>'
                . '<td>' . esc_html($word('rule_printer', $rule->id) ?: __('Standarddrucker', 'taxmod-label')) . '</td>'
                . '<td>' . esc_html($rule->copies === null ? __('wie Etikett', 'taxmod-label') : (string) $rule->copies) . '</td>'
                . '<td style="text-align:right">' . ListPage::openButton(self::url(['satz' => $rule->id])) . '</td></tr>';
        }

        return $body;
    }

    /** «Neue Regel», and «Vorwahlen übernehmen» with the list of what it would write — the dry run is the dialog itself. */
    private function buttons(LabelService $service): string
    {
        $form = static fn (string $inner): string => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false) . $inner . '</form>';
        $create = $form(ListPage::dialogAction('taxmod-label-new-rule', __('Neue Regel', 'taxmod-label'), __('Neue Regel', 'taxmod-label'),
            '<label style="display:block">' . esc_html__('Name', 'taxmod-label') . '<br>' . ListPage::field(InputKind::Line, 'name', '', ['required' => true]) . '</label>',
            'do', 'create', __('Anlegen', 'taxmod-label')));
        $open = PrintRuleChanges::open($service->ruleProposals(), $service->rules());
        if ($open === []) {
            return $create;
        }
        $labels = $service->catalog();
        $list   = '';
        foreach ($open as $proposal) {
            $list .= '<li>' . esc_html(sprintf('%s → %s%s', $proposal['name'], (string) ($labels->get($proposal['label'])['name'] ?? '#' . $proposal['label']),
                ($proposal['copies'] ?? null) === null ? '' : ' × ' . $proposal['copies'])) . '</li>';
        }

        return $create . ' ' . $form(ListPage::dialogAction('taxmod-label-takeover', sprintf(/* translators: %d: number of preselections */ __('Vorwahlen übernehmen (%d)', 'taxmod-label'), count($open)), __('Vorwahlen übernehmen', 'taxmod-label'),
            '<p>' . esc_html__('Diese Vorwahlen werden als Regeln angelegt, hinter den vorhandenen, in einem Schritt (rückgängig machbar). Die alten Vorwahlen bleiben, bis sie in einem eigenen Schritt entfernt werden.', 'taxmod-label') . '</p>'
            . '<ol style="max-height:40vh;overflow:auto">' . $list . '</ol>',
            'do', 'takeover', __('Übernehmen', 'taxmod-label')));
    }
}
