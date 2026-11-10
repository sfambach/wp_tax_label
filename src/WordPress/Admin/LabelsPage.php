<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Plugin as Modeller;
use TaxmodLabel\WordPress\EditorRoutes;
use TaxmodLabel\WordPress\LabelAbilities;
use TaxmodLabel\WordPress\LabelService;
use TaxmodLabel\WordPress\Plugin;

/**
 * Einstellungen › Druck › Etiketten (D-1292): the labels by category, and one opened, copied or new in the editor — drawing
 * beside the JSON, the size as fields above.
 *
 * ```mermaid
 * flowchart LR
 *   L["list (category, label, size, used by)"] -->|Öffnen| E["editor: drawing · JSON · settings"]
 *   L -->|Kopieren| E
 *   L -->|Neu| E
 *   E -->|REST preview / save| A["LabelAbilities"]
 *   L -->|Löschen: admin-post| R["LabelService::remove"]
 * ```
 *
 * @see docs/konzept.md §5
 */
final class LabelsPage
{
    public const SLUG = 'taxmod-label-labels';

    public const ACTION = 'taxmod_label_labels';

    public static function boot(): void
    {
        add_action('admin_post_' . self::ACTION, self::handle(...));
        add_action('admin_enqueue_scripts', self::enqueue(...));
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(['page' => 'taxmod-lager-modul', 'module' => self::SLUG] + $args, admin_url('admin.php'));
    }

    /** The editor's script and style only on its own page; the drawing comes from the server, never from the script. */
    public static function enqueue(): void
    {
        $module = sanitize_key(wp_unslash((string) ($_GET['module'] ?? '')));
        if ($module !== self::SLUG || (! isset($_GET['etikett']) && ! isset($_GET['neu']) && ! isset($_GET['kopie']))) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style('taxmod-label-editor', Plugin::url('assets/editor.css'), [], Plugin::VERSION);
        wp_enqueue_script('taxmod-label-editor', Plugin::url('assets/editor.js'), ['wp-i18n'], Plugin::VERSION, true);
        wp_set_script_translations('taxmod-label-editor', 'taxmod-label', Plugin::dir('languages'));
    }

    /** admin-post: capability → nonce → validate → act, every failure a message and the way back (D-1254). */
    public static function handle(): void
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            ListPage::fail(__('Keine Berechtigung.', 'taxmod-label'), self::url());
        }
        ListPage::nonce(self::ACTION);
        $doing = sanitize_key(wp_unslash((string) ($_POST['do'] ?? '')));
        $id    = absint($_POST['id'] ?? 0);
        ListPage::act(static function () use ($doing, $id): string {
            if ($doing !== 'remove' || $id <= 0) {
                throw new \InvalidArgumentException(__('Unbekannte Aktion.', 'taxmod-label'));
            }
            $answer = (new LabelAbilities())->remove(['id' => $id]);

            return self::url(['entfernt' => rawurlencode((string) $answer['label']['name'])]);
        }, self::url());
    }

    public function render(): string
    {
        $abilities = new LabelAbilities();
        $service   = new LabelService();
        if (! $service->structure()->hasNode('label_template')) {
            return '<p class="description">' . esc_html__('Das Modell kennt noch keine Etiketten.', 'taxmod-label') . '</p>';
        }
        $labels = $abilities->labels($service, true);
        $open   = absint($_GET['etikett'] ?? 0);
        $copy   = absint($_GET['kopie'] ?? 0);
        $new    = isset($_GET['neu']) ? absint($_GET['neu']) : -1;

        if ($open > 0 || $copy > 0 || $new >= 0) {
            $label = $labels[$open ?: $copy] ?? null;
            if (($open > 0 || $copy > 0) && $label === null) {
                return '<div class="notice notice-error inline"><p>' . esc_html(sprintf(/* translators: %d: id */ __('Kein Etikett: #%d.', 'taxmod-label'), $open ?: $copy)) . '</p></div>' . $this->back();
            }
            if ($copy > 0) {
                $label = ['id' => 0, 'name' => sprintf(/* translators: %s: the name of the original */ __('%s (Kopie)', 'taxmod-label'), $label['name'])] + $label;
            }
            $label ??= ['id' => 0, 'name' => '', 'node' => $new ?: $service->records()['root'], 'key' => '', 'width' => 0, 'height' => 0, 'roll' => 0, 'copies' => 1, 'layout' => '', 'rolls' => []];

            return $this->editor($label, $abilities->read([]));
        }

        return $this->notice() . $this->list($labels, $service);
    }

    /** @param array<int, array<string, mixed>> $labels */
    private function list(array $labels, LabelService $service): string
    {
        $users = $service->users();
        uasort($labels, static fn (array $a, array $b): int => strnatcasecmp($a['category'] . ' ' . $a['name'], $b['category'] . ' ' . $b['name']));
        $body = '';
        foreach ($labels as $id => $label) {
            $link  = self::url(['etikett' => $id]);
            $used  = $users[$id] ?? [];
            $body .= '<tr><th scope="row" class="check-column"></th>'
                . '<td>' . esc_html($label['category'] === '' ? '—' : $label['category']) . '</td>'
                . '<td><a href="' . esc_url($link) . '"><strong>' . esc_html($label['name'] ?: '#' . $id) . '</strong></a>'
                . ($label['complete'] ? '' : ' <span class="description">' . esc_html__('(unvollständig)', 'taxmod-label') . '</span>') . '</td>'
                . '<td>' . esc_html($label['width'] > 0 ? $label['width'] . ' × ' . $label['height'] . ' mm' . ($label['key'] === '' ? '' : ' · ' . $label['key']) : '—') . '</td>'
                . '<td>' . esc_html(implode(', ', $used) ?: '—') . '</td>'
                . '<td style="text-align:right;white-space:nowrap">'
                . ListPage::iconLink(ListPage::ICON_COPY, __('Kopieren', 'taxmod-label'), self::url(['kopie' => $id])) . ' '
                . ($used === [] ? $this->removeDialog($id, (string) $label['name']) . ' ' : '')
                . '<a class="button" href="' . esc_url($link) . '">' . esc_html__('Öffnen', 'taxmod-label') . '</a></td></tr>';
        }
        if ($body === '') {
            $body = '<tr class="no-items"><td class="colspanchange" colspan="6">' . esc_html__('Noch keine Etiketten.', 'taxmod-label') . '</td></tr>';
        }
        $categories = $service->records()['categories'];
        $new        = '';
        foreach ([$service->records()['root'] => __('ohne Kategorie', 'taxmod-label')] + $categories as $node => $name) {
            $new .= '<option value="' . esc_attr((string) $node) . '">' . esc_html($name) . '</option>';
        }

        return '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="text-align:right;margin-bottom:12px">'
            . '<input type="hidden" name="page" value="taxmod-lager-modul"><input type="hidden" name="module" value="' . esc_attr(self::SLUG) . '">'
            . '<label>' . esc_html__('Kategorie', 'taxmod-label') . ' <select name="neu">' . $new . '</select></label> '
            . '<button type="submit" class="button button-primary">' . esc_html__('Neues Etikett', 'taxmod-label') . '</button></form>'
            . ListPage::table([[__('Kategorie', 'taxmod-label'), '14em'], [__('Etikett', 'taxmod-label'), ''], [__('Größe', 'taxmod-label'), '14em'], [__('Benutzt von', 'taxmod-label'), '']], $body);
    }

    private function removeDialog(int $id, string $name): string
    {
        $body = '<p>' . esc_html(sprintf(/* translators: %s: label name */ __('«%s» löschen? Es verschwindet auch aus den Rollen, die es erlauben.', 'taxmod-label'), $name)) . '</p>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="id" value="' . esc_attr((string) $id) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . ListPage::dialogAction('taxmod-label-remove-' . $id, __('Löschen', 'taxmod-label'), __('Etikett löschen', 'taxmod-label'), $body, 'do', 'remove', __('Löschen', 'taxmod-label'), '', ListPage::ICON_DELETE)
            . '</form>';
    }

    /**
     * The editor's frame; editor.js fills it. Everything the script shows in words comes from here or from wp.i18n (AR-2).
     *
     * @param array<string, mixed> $label
     * @param array<string, mixed> $catalog
     */
    private function editor(array $label, array $catalog): string
    {
        $setup = [
            'label'      => $label,
            'categories' => $catalog['categories'],
            'rolls'      => $catalog['rolls'],
            'printers'   => $catalog['printers'],
            'rest'       => esc_url_raw(rest_url(EditorRoutes::NAMESPACE)),
            'nonce'      => wp_create_nonce('wp_rest'),
            'list'       => self::url(),
            'open'       => self::url(['etikett' => '']),
        ];
        $title = (int) $label['id'] > 0 ? (string) $label['name'] : __('Neues Etikett', 'taxmod-label');

        return '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('← alle Etiketten', 'taxmod-label') . '</a></p>'
            . '<h2>' . esc_html($title) . ((int) $label['id'] > 0 ? ' <span class="description" style="font-weight:normal">· Id: ' . esc_html((string) $label['id']) . '</span>' : '') . '</h2>'
            . '<div id="taxmod-label-editor" class="taxmod-label-editor" data-setup="' . esc_attr((string) wp_json_encode($setup, JSON_UNESCAPED_UNICODE)) . '">'
            . '<noscript><p>' . esc_html__('Der Editor braucht JavaScript.', 'taxmod-label') . '</p></noscript></div>';
    }

    private function notice(): string
    {
        $removed = sanitize_text_field(wp_unslash((string) ($_GET['entfernt'] ?? '')));

        return $removed === '' ? '' : '<div class="notice notice-success inline"><p>' . esc_html(sprintf(/* translators: %s: label name */ __('«%s» gelöscht.', 'taxmod-label'), rawurldecode($removed))) . '</p></div>';
    }

    private function back(): string
    {
        return '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('← alle Etiketten', 'taxmod-label') . '</a></p>';
    }
}
