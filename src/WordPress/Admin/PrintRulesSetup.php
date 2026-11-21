<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress\Admin;

use Taxmod\WordPress\Admin\RulesPage;
use TaxmodLabel\WordPress\LabelService;

/**
 * The labels module's part of the one rules page (D-1558): what its type «Druckregeln» needs there — the position, the choosers'
 * roots, the explanation — and the jump «Druck › Druckregeln» to that page. What a print rule decides stays in the print dialog.
 *
 * ```mermaid
 * flowchart LR
 *   T["Druckregeln: position, roots, hint"] -->|taxmod_rule_types| P["RulesPage (modeller), registered by the Lager"]
 *   M["Druck › Druckregeln"] -->|"redirect, tab = Druckregeln"| P
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1558
 */
final class PrintRulesSetup
{
    /** The old module address; it only leads to the one rules page. */
    public const SLUG = 'taxmod-label-rules';

    public static function boot(): void
    {
        add_filter(RulesPage::TYPES_FILTER, static fn (array $types): array => $types + self::printType());
        // The hook's name depends on the menu, so it is asked once the menu stands.
        add_action('admin_menu', static function (): void {
            add_action('load-' . get_plugin_page_hookname(self::SLUG, 'taxmod'), static function (): void {
                wp_safe_redirect(self::url());
                exit;
            });
        }, 999);
    }

    /** The print rules on the one page, one rule opened when given. */
    public static function url(int $rule = 0): string
    {
        $s = (new LabelService())->structure();

        return RulesPage::url($s->hasNode('label_rule') ? $s->node('label_rule') : 0, $rule > 0 ? ['satz' => $rule] : []);
    }

    /** @return array<int, array{position: int, roots: array<int, list<int>>, hint: string, one: string}> */
    private static function printType(): array
    {
        $s = (new LabelService())->structure();

        if (! $s->hasNode('label_rule')) {
            return [];
        }

        // D-1546, D-1548: the rule's node and its material group are chosen under the branches «Material steht in» names.
        $roots = array_values(array_filter(array_map('absint', (array) apply_filters('taxmod_material_roots', []))));
        $out   = [];

        foreach ($roots === [] ? [] : ['rule_node', 'rule_group'] as $key) {
            if ($s->hasField($key)) {
                $out[$s->field($key)] = $roots;
            }
        }

        return [$s->node('label_rule') => [
            'position' => $s->hasField('position') ? $s->field('position') : 0,
            'roots'    => $out,
            'hint'     => __('Beim Drucken gilt die erste Regel, die passt: sie wählt das Etikett, wenn gesetzt auch Drucker und Anzahl. Leere Bedingungen passen immer. Passt keine, bleibt die Vorwahl wie bisher; ohne Drucker in der Regel beginnt der Dialog mit dem Standarddrucker des Arbeitsplatzes.', 'taxmod-label'),
            'one'      => __('Druckregel', 'taxmod-label'),
        ]];
    }
}
