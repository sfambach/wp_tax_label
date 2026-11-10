<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use TaxmodLabel\WordPress\Admin\LabelsPage;
use TaxmodLabel\WordPress\Admin\PrintersPage;

/**
 * Wires the label module into WordPress once the modeller is there: the print button, the abilities, the editor's routes and
 * its two pages under Einstellungen › Druck of the logistics navigation (D-1292).
 *
 * ```mermaid
 * flowchart LR
 *   P["plugins_loaded"] --> C{"modeller ≥ MIN_MODELLER?"}
 *   C -->|no| N["admin notice"]
 *   C -->|yes| B["taxmod_label_button · taxmod_print_render"]
 *   C -->|yes| A["abilities taxmod-label/…"]
 *   C -->|yes| R["REST taxmod-label/v1"]
 *   C -->|yes| L["taxmod_logistics_pages: Etiketten, Drucker (area print)"]
 * ```
 *
 * @see docs/konzept.md §7
 */
final class Plugin
{
    public const VERSION = '0.0.1';

    /** D-1295: labelValues and queuePrint came with this modeller. */
    public const MIN_MODELLER = '0.2.61';

    private static string $file = '';

    public static function url(string $path): string
    {
        return plugins_url($path, self::$file);
    }

    public static function dir(string $path): string
    {
        return dirname(self::$file) . '/' . $path;
    }

    public function boot(string $file): void
    {
        self::$file = $file;
        // Checked at runtime, not with «Requires Plugins»: the modeller lives in a versioned folder on fambach.net.
        add_action('plugins_loaded', $this->bootWhenModellerIsThere(...));
    }

    public function bootWhenModellerIsThere(): void
    {
        load_plugin_textdomain('taxmod-label', false, dirname(plugin_basename(self::$file)) . '/languages');
        if (! class_exists(\Taxmod\WordPress\Plugin::class, false) || version_compare(\Taxmod\WordPress\Plugin::VERSION, self::MIN_MODELLER, '<')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html(sprintf(/* translators: %s: version */ __('Taxmod Label braucht den aktiven Taxonomy Modeller ab %s.', 'taxmod-label'), self::MIN_MODELLER)) . '</p></div>';
            });

            return;
        }
        LabelButton::boot();
        PrintRender::boot();
        add_action('wp_abilities_api_init', static fn () => (new LabelAbilities())->register());
        add_action('rest_api_init', static fn () => (new EditorRoutes())->register());
        add_filter('taxmod_logistics_pages', self::pages(...));
        LabelsPage::boot();
        if (self::handlesAdminActions()) {
            LabelButton::wireActions();
            LabelPrint::boot();
            PrintersPage::boot();
        }
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    public static function pages(array $pages): array
    {
        $pages[] = ['slug' => LabelsPage::SLUG, 'label' => __('Etiketten', 'taxmod-label'), 'area' => 'print', 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY, 'render' => static fn (): string => (new LabelsPage())->render()];
        $pages[] = ['slug' => PrintersPage::SLUG, 'label' => __('Drucker', 'taxmod-label'), 'area' => 'print', 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY, 'render' => static fn (): string => (new PrintersPage())->render()];

        return $pages;
    }

    /** The modeller's answer whether admin-post.php or admin-ajax.php serve this request (D-1187). */
    private static function handlesAdminActions(): bool
    {
        return ! method_exists(\Taxmod\WordPress\Plugin::class, 'handlesAdminActions') || \Taxmod\WordPress\Plugin::handlesAdminActions();
    }
}
