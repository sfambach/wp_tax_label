<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Draw\ToggleMarkup;
use Taxmod\WordPress\Plugin as Modeller;
use TaxmodLabel\Core\LabelContent;
use TaxmodLabel\Core\LabelFormat;
use TaxmodLabel\Core\LabelPrinter;
use TaxmodLabel\Core\PrinterMedia;
use TaxmodLabel\WordPress\LabelButton;
use TaxmodLabel\WordPress\LabelRender;
use TaxmodLabel\WordPress\LabelService;

/**
 * Einstellungen › Druck › Drucker (D-1192, moved by D-1292): the printers with their roll and the labels it allows, «Rolle
 * wechseln» in the shared dialog, each printer and roll opened in the modeller's record editor.
 *
 * @see docs/konzept.md §7
 */
final class PrintersPage
{
    public const SLUG = 'taxmod-label-printers';

    /** Stefan 2026-11-18: «sependir doch mal den eitiktten einen eigenen menüpunkt Druckmedium» — the rolls, out of the printers page. */
    public const MEDIA_SLUG = 'taxmod-label-media';

    /** Stefan 2026-11-18: «die einstellung unten Druckdialog würde ich dann in einen menüpunkt druck allgemein packen». */
    public const GENERAL_SLUG = 'taxmod-label-print-general';

    public const ACTION = 'taxmod_label_printers';

    public static function boot(): void
    {
        add_action('admin_post_' . self::ACTION, self::handle(...));
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(['page' => 'taxmod-lager-modul', 'module' => self::SLUG] + $args, admin_url('admin.php'));
    }

    public static function mediaUrl(array $args = []): string
    {
        return add_query_arg(['page' => 'taxmod-lager-modul', 'module' => self::MEDIA_SLUG] + $args, admin_url('admin.php'));
    }

    public static function generalUrl(array $args = []): string
    {
        return add_query_arg(['page' => 'taxmod-lager-modul', 'module' => self::GENERAL_SLUG] + $args, admin_url('admin.php'));
    }

    /** admin-post «Rolle wechseln»: capability → nonce → validate → act (D-1254). */
    public static function handle(): void
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            ListPage::fail(__('Keine Berechtigung.', 'taxmod-label'), self::url());
        }
        ListPage::nonce(self::ACTION);
        $doing = sanitize_text_field(wp_unslash((string) ($_POST['do'] ?? 'roll')));
        // D-1439: the test print of the fixed formats and the dialog's preview switch moved here from the modeller.
        if ($doing === 'preview') {
            $shown = (string) ($_POST[LabelButton::PREVIEW_OPTION] ?? '0') === '1';
            ListPage::act(static function () use ($shown): string {
                update_option(LabelButton::PREVIEW_OPTION, $shown ? '1' : '0', false);

                return self::generalUrl(['vorschau' => 1]);
            }, self::generalUrl());
        }
        if (str_starts_with($doing, 'test:')) {
            $key = sanitize_key(substr($doing, 5));
            ListPage::act(static function () use ($key): string {
                $formats = LabelFormat::shipped();
                if (! isset($formats[$key])) {
                    throw new \RuntimeException(__('Unbekanntes Format.', 'taxmod-label'));
                }
                $jobs = LabelRender::run([['label' => LabelContent::sample(), 'copies' => 1, 'format' => $formats[$key]]], LabelRender::TO_SERVICE)['jobs'];

                return self::url(['testdruck' => (int) ($jobs[0] ?? 0)]);
            }, self::url());
        }
        $printer = absint($_POST['printer'] ?? 0);
        if ($doing === 'active') {
            $on = (string) ($_POST['on'] ?? '') === '1';
            ListPage::act(static function () use ($printer, $on): string {
                (new LabelService())->switchPrinter($printer, $on);

                return self::url(['geschaltet' => 1]);
            }, self::url());
        }
        $roll    = absint($_POST['roll'] ?? 0);
        ListPage::act(static function () use ($printer, $roll): string {
            (new LabelService())->changeRoll($printer, $roll);

            return self::url(['gewechselt' => 1]);
        }, self::url());
    }

    private static function form(string $doing, string $fields, string $button): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . $fields . '<button type="submit" class="button" name="do" value="' . esc_attr($doing) . '">' . esc_html($button) . '</button></form>';
    }

    /** D-1439: the fixed formats with their test print, moved here from the modeller. */
    private function testPrints(): string
    {
        $form = self::form(...);
        $body = '';
        foreach (LabelFormat::shipped() as $format) {
            $body .= '<tr><th scope="row" class="check-column"></th><td>' . esc_html(LabelRender::formatName($format)) . '</td><td>' . esc_html(LabelButton::size($format)) . '</td>'
                . '<td style="text-align:right">' . $form('test:' . $format->key, '', __('Testdruck', 'taxmod-label')) . '</td></tr>';
        }

        return '<h2>' . esc_html__('Feste Formate', 'taxmod-label') . '</h2>'
            . '<p class="description">' . esc_html__('Der Testdruck geht mit einem Beispiel an den Druckdienst.', 'taxmod-label') . '</p>'
            . ListPage::table([[__('Format', 'taxmod-label'), ''], [__('Größe', 'taxmod-label'), '9em'], ['', '8em']], $body);
    }

    /** Einstellungen › Druck › Druck allgemein: settings that hold for every printer, starting with the dialog's preview switch. */
    public function general(): string
    {
        $switch = '<input type="hidden" name="' . esc_attr(LabelButton::PREVIEW_OPTION) . '" value="0"><label style="margin-right:.5em"><input type="checkbox" name="' . esc_attr(LabelButton::PREVIEW_OPTION) . '" value="1"'
            . checked(LabelButton::previewShown(), true, false) . '> ' . esc_html__('Vorschau des Etiketts im Druckdialog zeigen', 'taxmod-label') . '</label>';

        return (isset($_GET['vorschau']) ? '<div class="notice notice-success inline"><p>' . esc_html__('Gespeichert.', 'taxmod-label') . '</p></div>' : '')
            . '<h2>' . esc_html__('Druckdialog', 'taxmod-label') . '</h2>'
            . '<p>' . self::form('preview', $switch, __('Speichern', 'taxmod-label')) . '</p>';
    }

    public function render(): string
    {
        $service = new LabelService();
        $s       = $service->structure();
        if (! $s->hasNode('label_printer') || ! $s->hasNode('label_roll')) {
            return '<p class="description">' . esc_html__('Das Modell kennt noch keine Drucker und Rollen.', 'taxmod-label') . '</p>';
        }
        $catalog  = $service->catalog();
        $active   = $catalog->printerList();
        $printers = $service->names('label_printer');
        $rolls    = $service->names('label_roll');
        $chosen   = absint($_GET['satz'] ?? 0);
        $allowed  = static fn (?array $printer): string => implode(', ', array_unique(array_map(static fn (int $t): string => (string) ($catalog->get($t)['name'] ?? '#' . $t), $printer['allows'] ?? []))) ?: '—';

        if ($chosen > 0 && isset($printers[$chosen])) {
            return $this->details($chosen, $printers, $rolls, $active, $allowed, $service);
        }
        $body  = '';
        $state = $s->hasField('printer_active') ? $service->table('label_printer') : null;
        foreach ($printers as $id => $name) {
            $printer = $active[$id] ?? null;
            // D-1231: switched off, a missing queue or roll is no fault.
            $on      = $state === null || LabelPrinter::isOn($state->first($id, $s->field('printer_active')));
            $body   .= '<tr><th scope="row" class="check-column"></th>'
                // Stefan, 2026-11-18: the set-up stands in the printer's own view, not in the list.
                . '<td><a href="' . esc_url(self::url(['satz' => $id])) . '"><strong>' . esc_html($name) . '</strong></a></td>'
                . '<td>' . ($state === null ? '—' : self::activeSwitch($id, $name, $on)) . '</td>'
                . '<td>' . esc_html($printer !== null ? $printer['queue'] : ($on ? __('— (Warteschlange oder Rolle fehlt)', 'taxmod-label') : '—')) . '</td>'
                . '<td>' . esc_html(($printer['dpi'] ?? 0) > 0 ? $printer['dpi'] . ' dpi' : '203 dpi*') . '</td>'
                . '<td>' . esc_html($printer['roll_name'] ?? '—') . '</td>'
                . '<td>' . esc_html($allowed($printer)) . '</td>'
                . '<td style="text-align:right;white-space:nowrap">' . $this->changeRollDialog($id, $name, $rolls, $service) . ' <a class="button" href="' . esc_url(self::url(['satz' => $id])) . '">' . esc_html__('Öffnen', 'taxmod-label') . '</a></td></tr>';
        }
        $none = self::none(...);
        $help = '<details class="taxmod-help" style="margin:0 0 1em"><summary><strong>' . esc_html__('Neues Etikett einrichten', 'taxmod-label') . '</strong></summary><ol>'
            . '<li>' . esc_html__('Unter Etiketten das Etikett anlegen: Größe, Rolle, Anzahl, Layout.', 'taxmod-label') . '</li>'
            . '<li>' . esc_html__('Bei der Rolle eintragen, auf der es gedruckt wird.', 'taxmod-label') . '</li>'
            . '<li>' . esc_html__('Kennt der Drucker die Größe nicht nach Millimetern, die Größe am Drucker anlegen und unter «Seitengrößen» als «BreitexHöhe=Name» eintragen — wie, steht in seiner Einrichtung.', 'taxmod-label') . '</li>'
            . '<li>' . esc_html__('Testdruck aus dem Etikett; verschoben oder blass: Versatz, Zeilenlänge oder Layout anpassen.', 'taxmod-label') . '</li>'
            . '</ol></details>';

        return (isset($_GET['gewechselt']) ? '<div class="notice notice-success inline"><p>' . esc_html__('Rolle gewechselt: der Drucker druckt jetzt die Etiketten der neuen Rolle.', 'taxmod-label') . '</p></div>' : '')
            . (isset($_GET['testdruck']) ? '<div class="notice notice-success inline"><p>' . esc_html(sprintf(/* translators: %d: job number */ __('Testdruck wartet als Auftrag %d auf den Druckdienst.', 'taxmod-label'), absint($_GET['testdruck']))) . '</p></div>' : '')
            . $help
            . ListPage::table([[__('Drucker', 'taxmod-label'), '14em'], [__('Aktiv', 'taxmod-label'), '5em'], [__('Warteschlange', 'taxmod-label'), '12em'], [__('Auflösung', 'taxmod-label'), '7em'], [__('Eingelegte Rolle', 'taxmod-label'), '14em'], [__('Erlaubte Etiketten', 'taxmod-label'), '']], $body === '' ? $none(6) : $body)
            . '<p class="description">' . esc_html__('* ohne Angabe am Drucker prüft der Editor die Codes für 203 dpi.', 'taxmod-label') . '</p>'
            . $this->testPrints();
    }

    /** Einstellungen › Druck › Druckmedium: the rolls, each opened in the record editor. */
    public function media(): string
    {
        $service = new LabelService();
        $s       = $service->structure();
        $rolls   = $s->hasNode('label_roll') ? $service->names('label_roll') : [];
        $chosen  = absint($_GET['satz'] ?? 0);
        if ($chosen > 0 && isset($rolls[$chosen])) {
            $printers = $s->hasNode('label_printer') ? $service->names('label_printer') : [];
            $catalog  = $service->catalog();
            $allowed  = static fn (?array $printer): string => implode(', ', array_unique(array_map(static fn (int $t): string => (string) ($catalog->get($t)['name'] ?? '#' . $t), $printer['allows'] ?? []))) ?: '—';

            return $this->details($chosen, $printers, $rolls, $catalog->printerList(), $allowed, $service);
        }
        $kinds    = $service->mediumKinds();
        $printers = $service->names('label_printer');
        $media    = $service->media();
        $rollBody = '';
        foreach ($rolls as $id => $name) {
            $rollBody .= '<tr><th scope="row" class="check-column"></th><td><a href="' . esc_url(self::mediaUrl(['satz' => $id])) . '"><strong>' . esc_html($name) . '</strong></a></td>'
                . '<td>' . esc_html($kinds[$id] ?? '—') . '</td><td>' . esc_html(self::printerNames($media, $id, $printers)) . '</td>'
                . '<td style="text-align:right"><a class="button" href="' . esc_url(self::mediaUrl(['satz' => $id])) . '">' . esc_html__('Öffnen', 'taxmod-label') . '</a></td></tr>';
        }

        return '<h2>' . esc_html__('Druckmedium', 'taxmod-label') . '</h2>'
            . '<p class="description">' . esc_html__('Ein Druckmedium nennt seine Art, seine Breite und die Etiketten, die darauf gedruckt werden dürfen. Welcher Drucker es kann, steht am Drucker unter «Druckmedien».', 'taxmod-label') . '</p>'
            . ListPage::table([[__('Druckmedium', 'taxmod-label'), ''], [__('Art', 'taxmod-label'), '12em'], [__('Drucker', 'taxmod-label'), '']], $rollBody === '' ? self::none(3) : $rollBody);
    }

    /**
     * D-1441: the printers that can take this medium, derived from their «Druckmedien».
     *
     * @param array<int, string> $printers
     */
    private static function printerNames(?PrinterMedia $media, int $medium, array $printers): string
    {
        if ($media === null) {
            return '—';
        }

        return implode(', ', array_map(static fn (int $p): string => $printers[$p] ?? '#' . $p, $media->printersFor($medium))) ?: '—';
    }

    private static function none(int $columns): string
    {
        return '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr((string) ($columns + 2)) . '">' . esc_html__('Nichts gefunden.', 'taxmod-label') . '</td></tr>';
    }

    /**
     * One printer or roll: its own fields in the record editor, and the facts beside them (D-1220).
     *
     * @param array<int, string>               $printers
     * @param array<int, string>               $rolls
     * @param array<int, array<string, mixed>> $active
     * @param \Closure(?array<string, mixed>): string $allowed
     */
    private function details(int $record, array $printers, array $rolls, array $active, \Closure $allowed, LabelService $service): string
    {
        $s      = $service->structure();
        $isRoll = isset($rolls[$record]);
        $here   = $isRoll ? self::mediaUrl(...) : self::url(...);
        $form   = (string) apply_filters('taxmod_record_editor', '', $record, $here(['satz' => $record]));
        $name   = $isRoll ? $rolls[$record] : $printers[$record];
        if ($isRoll) {
            $holders = array_filter($active, static fn (array $p): bool => ($p['roll_name'] ?? '') === $name);
            $facts   = '<tr><th scope="row" style="width:14em">' . esc_html__('Art', 'taxmod-label') . '</th><td>' . esc_html($service->mediumKinds()[$record] ?? '—') . '</td></tr>'
                . '<tr><th scope="row">' . esc_html__('Drucker, die es können', 'taxmod-label') . '</th><td>' . esc_html(self::printerNames($service->media(), $record, $printers)) . '</td></tr>'
                . '<tr><th scope="row">' . esc_html__('Eingelegt in', 'taxmod-label') . '</th><td>'
                . esc_html(implode(', ', array_map(static fn (array $p): string => (string) $p['name'], $holders)) ?: '—') . '</td></tr>';
            $actions = '';
        } else {
            $printer = $active[$record] ?? null;
            $howto   = $s->hasField('printer_setup') ? trim((string) $service->table('label_printer')->first($record, $s->field('printer_setup'))) : '';
            $facts   = '<tr><th scope="row" style="width:14em">' . esc_html__('Eingelegte Rolle', 'taxmod-label') . '</th><td>' . esc_html($printer['roll_name'] ?? '—') . '</td></tr>'
                . '<tr><th scope="row">' . esc_html__('Erlaubte Etiketten', 'taxmod-label') . '</th><td>' . esc_html($allowed($printer)) . '</td></tr>'
                . ($howto === '' ? '' : '<tr><th scope="row">' . esc_html__('Einrichtung', 'taxmod-label') . '</th><td>' . esc_html($howto) . '</td></tr>');
            $actions = $this->changeRollDialog($record, $name, $rolls, $service);
        }

        return '<p><a href="' . esc_url($here()) . '">' . esc_html($isRoll ? __('← Druckmedium', 'taxmod-label') : __('← Drucker', 'taxmod-label')) . '</a></p>'
            . '<h2>' . esc_html($name) . '</h2>'
            // D-1308: the page's buttons stand in the one toolbar, top left — not on a line of their own on the right (Stefan, 2026-11-18).
            . ListPage::toolbar([$actions])
            . ListPage::beside($form, '<table class="widefat striped">' . $facts . '</table>');
    }

    /**
     * D-1231: the printer's on/off switch in its row — the modeller's look (ToggleMarkup), sent in the background like every act (D-1408).
     */
    private static function activeSwitch(int $printer, string $name, bool $on): string
    {
        $title = sprintf($on ? /* translators: %s: printer */ __('%s abschalten', 'taxmod-label') : /* translators: %s: printer */ __('%s einschalten', 'taxmod-label'), $name);

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="printer" value="' . esc_attr((string) $printer) . '">'
            . '<input type="hidden" name="on" value="' . ($on ? '0' : '1') . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . '<button type="submit" name="do" value="active" class="button-link" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '" title="' . esc_attr($title) . '" aria-label="' . esc_attr($title) . '">'
            . ToggleMarkup::track($on) . '</button></form>';
    }

    /**
     * D-1441, D-1233 alike: «Eingelegte Rolle» changes only through «Rolle wechseln», which keeps it among the printer's media; the
     * record editor shows it read-only with that button (D-1334).
     *
     * @return array<int, array{label: string, url: string}>
     */
    public static function claimedFields(mixed $claims, int $recordId, int $nodeId): array
    {
        $claims = is_array($claims) ? $claims : [];
        try {
            $s = (new LabelService())->structure();
            if ($s->hasNode('label_printer') && $nodeId === $s->node('label_printer') && $s->hasField('printer_loaded_roll')) {
                // Stefan 2026-11-18: «Rolle wechseln» once, in the toolbar top left — the form shows the roll without a second button.
                $claims[$s->field('printer_loaded_roll')] = ['label' => __('Rolle wechseln', 'taxmod-label'), 'url' => ''];
            }
        } catch (\RuntimeException) {
            return $claims;
        }

        return $claims;
    }

    /** @param array<int, string> $rolls */
    private function changeRollDialog(int $printer, string $name, array $rolls, LabelService $service): string
    {
        $s = $service->structure();
        if ($rolls === [] || ! $s->hasField('printer_loaded_roll')) {
            return '';
        }
        $current = (int) $service->table('label_printer')->first($printer, $s->field('printer_loaded_roll'));
        $media   = $service->media();
        if ($media !== null) {
            // D-1441: only what this printer can take.
            $rolls = array_intersect_key($rolls, array_flip($media->of($printer)));
            if ($rolls === []) {
                return '<span class="description">' . esc_html__('Keine Druckmedien eingetragen', 'taxmod-label') . '</span>';
            }
        }
        $options = '';
        foreach ($rolls as $id => $roll) {
            $options .= '<option value="' . esc_attr((string) $id) . '"' . selected($id, $current, false) . '>' . esc_html($roll) . '</option>';
        }
        $body = '<label style="display:block">' . esc_html__('Welche Rolle ist jetzt eingelegt?', 'taxmod-label') . '<br><select name="roll" style="width:100%">' . $options . '</select></label>'
            . '<p class="description">' . esc_html(sprintf(/* translators: %s: printer */ __('%s druckt danach nur die Etiketten, die diese Rolle erlaubt.', 'taxmod-label'), $name)) . '</p>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="printer" value="' . esc_attr((string) $printer) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::dialogAction('taxmod-label-change-roll-' . $printer, __('Rolle wechseln', 'taxmod-label'), __('Rolle wechseln', 'taxmod-label'), $body, 'do', 'roll', __('Übernehmen', 'taxmod-label'))
            . '</form>';
    }
}
