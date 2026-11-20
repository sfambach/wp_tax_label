<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\WordPress\Plugin as Modeller;
use TaxmodLabel\Core\WorkplaceMenu;
use TaxmodLabel\WordPress\Admin\WorkplacesPage;

/**
 * D-1502 Stufe 3: the workplace in the admin bar — «Arbeitsplatz: X», a click switches it — and a reminder on Taxmod pages while
 * none is chosen. Only the person's user meta changes.
 *
 * ```mermaid
 * flowchart LR
 *   B["admin bar «Arbeitsplatz: X»"] -->|click, nonce| P["admin-post switch"]
 *   P -->|update_user_meta| U["taxmod_label_workplace"]
 *   P --> R["back to the page"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/pakete/arbeitsplatz/konzept.md
 */
final class WorkplaceSwitch
{
    public const ACTION = 'taxmod_label_workplace_switch';

    public static function boot(): void
    {
        add_action('admin_bar_menu', self::bar(...), 80);
        add_action('admin_post_' . self::ACTION, self::handle(...));
        add_action('admin_notices', self::notice(...));
    }

    private static ?WorkplaceMenu $menu = null;

    private static bool $read = false;

    /** null where the person may not print or the model has no workplaces; the workplaces are read once per request (CD-7). */
    private static function menu(): ?WorkplaceMenu
    {
        if (self::$read) {
            return self::$menu;
        }
        self::$read = true;
        if (! is_user_logged_in() || ! current_user_can(Modeller::CAPABILITY)) {
            return null;
        }
        $service = new LabelService();
        if ($service->workplaces() === null) {
            return null;
        }

        // The node's records only: a workplace in the trash is no longer one of them (D-1509).
        return self::$menu = new WorkplaceMenu($service->workplaceNames(), $service->workplace());
    }

    /** Prüftor D-1518: the switch and the reminder stand on Taxmod's own admin pages only. */
    private static function onTaxmodPage(): bool
    {
        $screen = is_admin() && function_exists('get_current_screen') ? get_current_screen() : null;

        return $screen !== null && str_contains((string) $screen->id, 'taxmod');
    }

    public static function url(int $place): string
    {
        return wp_nonce_url(add_query_arg(['action' => self::ACTION, 'platz' => $place], admin_url('admin-post.php')), self::ACTION);
    }

    public static function bar(\WP_Admin_Bar $bar): void
    {
        $menu = self::onTaxmodPage() ? self::menu() : null;
        if ($menu === null) {
            return;
        }
        $name = $menu->currentName();
        $bar->add_node([
            'id'    => 'taxmod-workplace',
            'title' => esc_html($name === '' ? __('Kein Arbeitsplatz', 'taxmod-label') : sprintf(/* translators: %s: workplace */ __('Arbeitsplatz: %s', 'taxmod-label'), $name)),
            'href'  => WorkplacesPage::url(),
        ]);
        foreach ($menu->choices() as $choice) {
            $bar->add_node([
                'parent' => 'taxmod-workplace',
                'id'     => 'taxmod-workplace-' . $choice['id'],
                'title'  => ($choice['current'] ? '✓ ' : '') . esc_html($choice['id'] === 0 ? __('— kein Arbeitsplatz —', 'taxmod-label') : $choice['name']),
                'href'   => self::url($choice['id']),
            ]);
        }
        $bar->add_node(['parent' => 'taxmod-workplace', 'id' => 'taxmod-workplace-manage', 'title' => esc_html__('Arbeitsplätze verwalten', 'taxmod-label'), 'href' => WorkplacesPage::url()]);
    }

    /** admin-post: capability → nonce → the workplace exists (not in the trash) → user meta (CD-5, D-1518). */
    public static function handle(): never
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'taxmod-label'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $place = absint($_GET['platz'] ?? 0);
        $menu  = self::menu();
        if ($menu !== null && $menu->allows($place)) {
            update_user_meta(get_current_user_id(), LabelService::WORKPLACE_META, $place);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /** The reminder on Taxmod's own pages while workplaces exist and none is chosen, in WordPress's own notice (D-1518). */
    public static function reminder(): string
    {
        $menu = self::onTaxmodPage() ? self::menu() : null;
        if ($menu === null || ! $menu->remindsToChoose()) {
            return '';
        }
        $links = implode(' · ', array_map(
            static fn (array $choice): string => '<a href="' . esc_url(self::url($choice['id'])) . '">' . esc_html($choice['name']) . '</a>',
            array_filter($menu->choices(), static fn (array $choice): bool => $choice['id'] > 0)
        ));

        return wp_get_admin_notice(esc_html__('Kein Arbeitsplatz gewählt — der Druckdialog zeigt alle Drucker. Wo bist du?', 'taxmod-label') . ' ' . $links, ['type' => 'info']);
    }

    public static function notice(): void
    {
        echo self::reminder(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in reminder()
    }
}
