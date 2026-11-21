<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use TaxmodLabel\WordPress\Admin\LabelsPage;
use TaxmodLabel\WordPress\Admin\PrintersPage;
use TaxmodLabel\WordPress\Admin\PrintRulesSetup;
use TaxmodLabel\WordPress\Admin\WorkplacesPage;

/**
 * Wires the label module into WordPress once the modeller is there: the print button, the abilities, the editor's routes and
 * its two pages under Einstellungen › Druck of the logistics navigation (D-1292).
 *
 * ```mermaid
 * flowchart LR
 *   P["plugins_loaded"] --> C{"modeller ≥ MIN_MODELLER?"}
 *   C -->|no| N["admin notice"]
 *   C -->|yes| B["taxmod_label_button · taxmod_print_render"]
 *   C -->|yes| S["script taxmod-label62 (D-1507)"]
 *   C -->|yes| A["abilities taxmod-label/…"]
 *   C -->|yes| R["REST taxmod-label/v1"]
 *   C -->|yes| L["taxmod_logistics_pages: Allgemein, Etiketten, Druckregeln, Drucker, Arbeitsplätze, Druckmedium (area print)"]
 *   C -->|yes| U["taxmod_user_settings: Arbeitsplatz (D-1502)"]
 * ```
 *
 * @see docs/konzept.md §7
 */
final class Plugin
{
    public const VERSION = '0.0.44';

    /** D-1295: labelValues and queuePrint came with 0.2.61; DialogNumber since 0.2.133 (D-1431); button/iconButton/listBar since 0.2.172 (D-1500); several roots of a choice since 0.2.200 (D-1546); the one rules page since 0.2.206 (D-1558). */
    public const MIN_MODELLER = '0.2.206';

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
        // D-1439: the one render path, asked by the stock's bulk print.
        LabelRender::boot();
        // D-1507: the 62 mm postage print, attached by other modules through its handle.
        Label62Script::boot();
        PostageStore::boot();
        add_action('wp_abilities_api_init', static fn () => (new LabelAbilities())->register());
        add_action('rest_api_init', static fn () => (new EditorRoutes())->register());
        add_filter('taxmod_logistics_pages', self::pages(...));
        add_filter('taxmod_claimed_fields', [PrintersPage::class, 'claimedFields'], 10, 3);
        add_filter(LabelService::CATALOG_FILTER, LabelService::answerCatalog(...));
        add_filter(LabelService::TAKEOVER_FILTER, LabelService::answerTakeover(...), 10, 2);
        // D-1483: a label, printer, roll, workplace or print rule opens on its own page, not in the Modeller (Stefan 21.11. 15:08).
        add_filter('taxmod_record_home', RecordHome::answer(...), 10, 3);
        // D-1502: the line «Arbeitsplatz» in the stock's Benutzereinstellungen.
        add_filter('taxmod_user_settings', WorkplacesPage::userSetting(...));
        LabelsPage::boot();
        // D-1502 Stufe 3: «Arbeitsplatz: X» in the admin bar, switched with one click, and a reminder while none is chosen.
        WorkplaceSwitch::boot();
        PrintRulesSetup::boot();
        if (self::handlesAdminActions()) {
            LabelButton::wireActions();
            PostagePrint::wire();
            LabelPrint::boot();
            PrintersPage::boot();
            WorkplacesPage::boot();
        }
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    public static function pages(array $pages): array
    {
        $pages[] = ['slug' => LabelsPage::SLUG, 'label' => __('Etiketten', 'taxmod-label'), 'area' => 'print', 'position' => 20, 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY, 'render' => static fn (): string => (new LabelsPage())->render()];
        // D-1519 R4, D-1558: Einstellungen › Druck › Druckregeln, after the labels they choose from — a jump to the one rules page.
        $pages[] = ['slug' => PrintRulesSetup::SLUG, 'label' => __('Druckregeln', 'taxmod-label'), 'area' => 'print', 'position' => 30, 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY, 'render' => null];
        $pages[] = ['slug' => PrintersPage::SLUG, 'label' => __('Drucker', 'taxmod-label'), 'area' => 'print', 'position' => 40, 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY, 'render' => static fn (): string => (new PrintersPage())->render()];
        $pages[] = ['slug' => WorkplacesPage::SLUG, 'label' => __('Arbeitsplätze', 'taxmod-label'), 'area' => 'masterdata', 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY, 'render' => static fn (): string => (new WorkplacesPage())->render()];
        $pages[] = ['slug' => PrintersPage::MEDIA_SLUG, 'label' => __('Druckmedium', 'taxmod-label'), 'area' => 'print', 'position' => 50, 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY, 'render' => static fn (): string => (new PrintersPage())->media()];
        $pages[] = ['slug' => PrintersPage::GENERAL_SLUG, 'label' => __('Allgemein', 'taxmod-label'), 'area' => 'print', 'position' => 10, 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY, 'render' => static fn (): string => (new PrintersPage())->general()];

        return $pages;
    }

    /** The modeller's answer whether admin-post.php or admin-ajax.php serve this request (D-1187). */
    private static function handlesAdminActions(): bool
    {
        return ! method_exists(\Taxmod\WordPress\Plugin::class, 'handlesAdminActions') || \Taxmod\WordPress\Plugin::handlesAdminActions();
    }
}
