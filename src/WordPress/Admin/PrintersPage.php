<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Plugin as Modeller;
use TaxmodLabel\Core\LabelContent;
use TaxmodLabel\Core\LabelFormat;
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

                return self::url(['vorschau' => 1]);
            }, self::url());
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
        $roll    = absint($_POST['roll'] ?? 0);
        ListPage::act(static function () use ($printer, $roll): string {
            (new LabelService())->changeRoll($printer, $roll);

            return self::url(['gewechselt' => 1]);
        }, self::url());
    }

    /** D-1439: the fixed formats with their test print, and the dialog's preview switch — both moved here from the modeller. */
    private function testPrints(): string
    {
        $form = static fn (string $doing, string $fields, string $button): string => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">' . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . $fields . '<button type="submit" class="button" name="do" value="' . esc_attr($doing) . '">' . esc_html($button) . '</button></form>';
        $body = '';
        foreach (LabelFormat::shipped() as $format) {
            $body .= '<tr><th scope="row" class="check-column"></th><td>' . esc_html(LabelRender::formatName($format)) . '</td><td>' . esc_html(LabelButton::size($format)) . '</td>'
                . '<td style="text-align:right">' . $form('test:' . $format->key, '', __('Testdruck', 'taxmod-label')) . '</td></tr>';
        }
        $switch = '<input type="hidden" name="' . esc_attr(LabelButton::PREVIEW_OPTION) . '" value="0"><label style="margin-right:.5em"><input type="checkbox" name="' . esc_attr(LabelButton::PREVIEW_OPTION) . '" value="1"'
            . checked(LabelButton::previewShown(), true, false) . '> ' . esc_html__('Vorschau des Etiketts im Druckdialog zeigen', 'taxmod-label') . '</label>';

        return '<h2>' . esc_html__('Feste Formate', 'taxmod-label') . '</h2>'
            . '<p class="description">' . esc_html__('Der Testdruck geht mit einem Beispiel an den Druckdienst.', 'taxmod-label') . '</p>'
            . ListPage::table([[__('Format', 'taxmod-label'), ''], [__('Größe', 'taxmod-label'), '9em'], ['', '8em']], $body)
            . '<h2>' . esc_html__('Druckdialog', 'taxmod-label') . '</h2>'
            . '<p>' . $form('preview', $switch, __('Speichern', 'taxmod-label')) . '</p>';
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
        $setup = $s->hasField('printer_setup') ? $service->table('label_printer') : null;
        $body  = '';
        foreach ($printers as $id => $name) {
            $printer = $active[$id] ?? null;
            $howto   = $setup === null ? '' : trim((string) $setup->first($id, $s->field('printer_setup')));
            $body   .= '<tr><th scope="row" class="check-column"></th>'
                . '<td><a href="' . esc_url(self::url(['satz' => $id])) . '"><strong>' . esc_html($name) . '</strong></a>'
                . ($howto === '' ? '' : '<details><summary>' . esc_html__('Einrichtung', 'taxmod-label') . '</summary><div>' . esc_html($howto) . '</div></details>') . '</td>'
                . '<td>' . esc_html($printer === null ? __('— (Warteschlange oder Rolle fehlt)', 'taxmod-label') : $printer['queue']) . '</td>'
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
            . (isset($_GET['vorschau']) ? '<div class="notice notice-success inline"><p>' . esc_html__('Gespeichert.', 'taxmod-label') . '</p></div>' : '')
            . $help
            . ListPage::table([[__('Drucker', 'taxmod-label'), '14em'], [__('Warteschlange', 'taxmod-label'), '12em'], [__('Auflösung', 'taxmod-label'), '7em'], [__('Eingelegte Rolle', 'taxmod-label'), '14em'], [__('Erlaubte Etiketten', 'taxmod-label'), '']], $body === '' ? $none(5) : $body)
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
        $rollBody = '';
        foreach ($rolls as $id => $name) {
            $rollBody .= '<tr><th scope="row" class="check-column"></th><td><a href="' . esc_url(self::mediaUrl(['satz' => $id])) . '"><strong>' . esc_html($name) . '</strong></a></td>'
                . '<td style="text-align:right"><a class="button" href="' . esc_url(self::mediaUrl(['satz' => $id])) . '">' . esc_html__('Öffnen', 'taxmod-label') . '</a></td></tr>';
        }

        return '<h2>' . esc_html__('Rollen', 'taxmod-label') . '</h2>'
            . '<p class="description">' . esc_html__('Eine Rolle nennt ihre Breite und die Etiketten, die auf ihr gedruckt werden dürfen.', 'taxmod-label') . '</p>'
            . ListPage::table([[__('Rolle', 'taxmod-label'), '']], $rollBody === '' ? self::none(1) : $rollBody);
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
            $facts   = '<tr><th scope="row" style="width:14em">' . esc_html__('Eingelegt in', 'taxmod-label') . '</th><td>'
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
            . '<div style="text-align:right;margin-bottom:12px">' . $actions . '</div>'
            . ListPage::beside($form, '<table class="widefat striped">' . $facts . '</table>');
    }

    /** @param array<int, string> $rolls */
    private function changeRollDialog(int $printer, string $name, array $rolls, LabelService $service): string
    {
        $s = $service->structure();
        if ($rolls === [] || ! $s->hasField('printer_loaded_roll')) {
            return '';
        }
        $current = (int) $service->table('label_printer')->first($printer, $s->field('printer_loaded_roll'));
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
