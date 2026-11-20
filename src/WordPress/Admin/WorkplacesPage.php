<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress\Admin;

use Taxmod\Core\Description\InputKind;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\RecordPick;
use Taxmod\WordPress\Plugin as Modeller;
use TaxmodLabel\WordPress\LabelService;

/**
 * Logistik › Stammdaten › Arbeitsplätze (D-1502; Stufe 3, his word 2026-11-20 «ein Arbeitsplatz ist ein Stammdatum»): the shared
 * workplaces with their printers, each opened in the modeller's record editor beside what it can print, and «Neuer Arbeitsplatz»; the
 * person chooses theirs under Benutzereinstellungen or in the admin bar.
 *
 * @see wp-taxonomy-modeler docs/pakete/arbeitsplatz/konzept.md
 */
final class WorkplacesPage
{
    public const SLUG = 'taxmod-label-workplaces';

    public const ACTION = 'taxmod_label_workplaces';

    public static function boot(): void
    {
        add_action('admin_post_' . self::ACTION, self::handle(...));
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(['page' => 'taxmod-lager-modul', 'module' => self::SLUG] + $args, admin_url('admin.php'));
    }

    /** admin-post «Neuer Arbeitsplatz»: capability → nonce → validate → sanitize → act (CD-5, D-1254). */
    public static function handle(): void
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            ListPage::fail(__('Keine Berechtigung.', 'taxmod-label'), self::url());
        }
        ListPage::nonce(self::ACTION);
        $name = sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')));
        ListPage::act(static function () use ($name): string {
            if ($name === '') {
                throw new \RuntimeException(__('Der Arbeitsplatz braucht einen Namen.', 'taxmod-label'));
            }

            $place = (new LabelService())->createWorkplace($name);
            // Stefan 20.11. 18:15: whoever creates a workplace works there — it is theirs at once, not after a second visit to the settings.
            $place > 0 && update_user_meta(get_current_user_id(), LabelService::WORKPLACE_META, $place);

            return self::url(['satz' => $place]);
        }, self::url());
    }

    public function render(): string
    {
        $service = new LabelService();
        if ($service->workplaces() === null) {
            return '<p>' . esc_html__('Im Modell gibt es noch keinen Knoten «Arbeitsplätze».', 'taxmod-label') . '</p>';
        }
        $places   = $service->workplaceNames();
        $printers = $service->names('label_printer');
        $chosen   = absint($_GET['satz'] ?? 0);
        $explain  = __('Ein Arbeitsplatz nennt die verfügbaren Drucker, die dort stehen, und den, mit dem der Druckdialog beginnt (★). Wer unter Benutzereinstellungen einen Arbeitsplatz wählt, sieht im Druckdialog nur dessen Drucker.', 'taxmod-label');
        if ($chosen > 0 && isset($places[$chosen])) {
            return ListPage::recordHead(__('Arbeitsplatz', 'taxmod-label'), $places[$chosen], self::url(), __('Arbeitsplätze', 'taxmod-label'), $explain)
                . ListPage::beside((string) apply_filters('taxmod_record_editor', '', $chosen, self::url(['satz' => $chosen])), $this->facts($chosen, $service));
        }
        $records = array_filter(array_map(static fn (int $id): ?\TaxmodLabel\Core\Workplace => $service->workplaceRecord($id), array_keys($places)));
        $sites   = $service->recordNames(array_map(static fn (\TaxmodLabel\Core\Workplace $w): int => $w->location, $records));
        $body    = '';
        foreach ($records as $place) {
            $names = array_map(static fn (int $p): string => ($p === $place->defaultPrinter ? '★ ' : '') . ($printers[$p] ?? '#' . $p), $place->printers);
            $body .= '<tr><th scope="row" class="check-column"></th><td><a href="' . esc_url(self::url(['satz' => $place->id])) . '"><strong>' . esc_html($places[$place->id]) . '</strong></a></td>'
                . '<td>' . esc_html(implode(', ', $names) ?: '—') . '</td>'
                // Stefan 20.11. 18:21: «der gewählte Standarddrucker und der Platz … ganz nett zu sehen».
                . '<td>' . esc_html($place->defaultPrinter > 0 ? ($printers[$place->defaultPrinter] ?? '#' . $place->defaultPrinter) : '—') . '</td>'
                . '<td>' . esc_html($place->location > 0 ? ($sites[$place->location] ?? '#' . $place->location) : '—') . '</td>'
                . '<td style="text-align:right">' . ListPage::openButton(self::url(['satz' => $place->id])) . '</td></tr>';
        }
        $create = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::dialogAction('taxmod-label-new-workplace', __('Neuer Arbeitsplatz', 'taxmod-label'), __('Neuer Arbeitsplatz', 'taxmod-label'),
                '<label style="display:block">' . esc_html__('Name', 'taxmod-label') . '<br>' . ListPage::field(InputKind::Line, 'name', '', ['required' => true]) . '</label>',
                'do', 'create', __('Anlegen', 'taxmod-label'))
            . '</form>';

        // D-1526: one head with the count and the explanation behind its «?», one bar with the button.
        return ListPage::listHead(__('Arbeitsplätze', 'taxmod-label'), count($places), $explain)
            . ListPage::listBar('', buttons: $create)
            . ListPage::table([[__('Arbeitsplatz', 'taxmod-label'), ''], [__('Verfügbare Drucker', 'taxmod-label'), ''], [__('Standarddrucker', 'taxmod-label'), ''], [__('Lagerort', 'taxmod-label'), '']], $body === ''
                ? '<tr class="no-items"><td class="colspanchange" colspan="6">' . esc_html__('Nichts gefunden.', 'taxmod-label') . '</td></tr>' : $body);
    }

    /**
     * Stufe 3: what this workplace can print, derived — each printer with its loaded roll and the labels that roll allows, the
     * default printer first (★). Nothing of it is stored at the workplace.
     */
    private function facts(int $place, LabelService $service): string
    {
        $workplace = $service->workplaceRecord($place);
        $catalog   = $service->catalog();
        $printers  = $workplace === null ? [] : $catalog->at($workplace)->printerList();
        $rows      = '';
        foreach ($printers as $id => $printer) {
            $labels = implode(', ', array_unique(array_map(static fn (int $t): string => (string) ($catalog->get($t)['name'] ?? '#' . $t), $printer['allows'] ?? [])));
            $rows  .= '<tr><th scope="row" style="width:14em">' . esc_html(($id === $workplace?->defaultPrinter ? '★ ' : '') . (string) ($printer['name'] ?? '#' . $id)) . '</th>'
                . '<td>' . esc_html((string) ($printer['roll_name'] ?? '') ?: '—') . '<br><span class="description">' . esc_html($labels ?: __('keine Etiketten', 'taxmod-label')) . '</span></td></tr>';
        }

        return '<table class="widefat striped"><thead><tr><th colspan="2">' . esc_html__('Was hier gedruckt werden kann', 'taxmod-label') . '</th></tr></thead>'
            . ($rows === '' ? '<tr><td colspan="2">' . esc_html__('Keine aktiven Drucker zugewiesen.', 'taxmod-label') . '</td></tr>' : $rows) . '</table>';
    }

    /**
     * D-1502: the line «Arbeitsplatz» in the stock's Benutzereinstellungen (filter `taxmod_user_settings`).
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public static function userSetting(mixed $lines): array
    {
        $lines   = is_array($lines) ? $lines : [];
        $service = new LabelService();
        if ($service->workplaces() === null) {
            return $lines;
        }
        $places  = $service->workplaceNames();
        $current = $service->workplace()?->id;
        $lines[] = [
            'label'   => __('Arbeitsplatz', 'taxmod-label'),
            // D-1508: a choice of records is the modeller's record chooser.
            'control' => RecordPick::field(LabelService::WORKPLACE_META, [['depth' => 0, 'name' => __('Arbeitsplätze', 'taxmod-label'), 'records' => $places]], $current)
                . '<p class="description">' . esc_html__('Der Druckdialog zeigt nur die Drucker dieses Arbeitsplatzes; ohne Arbeitsplatz alle.', 'taxmod-label') . '</p>',
            // The stock has already checked capability and nonce for its own form (CD-5).
            'save'    => static function () use ($places): void {
                $id = absint($_POST[LabelService::WORKPLACE_META] ?? 0);
                if ($id > 0 && ! isset($places[$id])) {
                    return;
                }
                update_user_meta(get_current_user_id(), LabelService::WORKPLACE_META, $id);
            },
        ];

        return $lines;
    }
}
