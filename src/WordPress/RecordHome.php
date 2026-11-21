<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\WordPress\ModelDoor;
use TaxmodLabel\WordPress\Admin\LabelsPage;
use TaxmodLabel\WordPress\Admin\PrintersPage;
use TaxmodLabel\WordPress\Admin\PrintRulesSetup;
use TaxmodLabel\WordPress\Admin\WorkplacesPage;

/**
 * The page of a record this module owns, for the modeller's `taxmod_record_home` (D-1483): a label opens in the label editor, a
 * printer, roll, workplace or print rule on its own page — not in the Taxonomy Modeller.
 *
 * ⚠️ *Stefan 21.11. 15:08: «wenn ich auf das Auge bei Etikett gehe, der den Etiketteneditor öffnet … aber nicht in den Taxonomy.
 * Das sollten wir überall mal lindern».*
 *
 * ```mermaid
 * flowchart LR
 *   E["eye at a field"] -->|"record, target node"| H["taxmod_record_home"]
 *   H -->|"node is one of ours"| P["label editor / printer / workplace / rule"]
 *   H -->|"else"| M["the modeller's page"]
 * ```
 */
final class RecordHome
{
    /** Structure key → the page of one of its records. */
    private const PAGES = ['label_template', 'label_printer', 'label_roll', 'label_workplace', 'label_rule'];

    public static function answer(?string $url, int $record, int $node): ?string
    {
        if ($url !== null && $url !== '' || $record <= 0) {
            return $url;
        }

        try {
            $s = (new LabelService())->structure();
        } catch (\RuntimeException | \InvalidArgumentException) {
            return $url;
        }

        $ours = [];

        foreach (self::PAGES as $key) {
            if ($s->hasNode($key)) {
                $ours[$s->node($key)] = $key;
            }
        }

        // The field's target names the node; where it is a parent of ours, the record's own node decides (one read, CD-7).
        $key = $ours[$node] ?? $ours[(int) ((new ModelDoor())->nodesOf([$record])[$record] ?? 0)] ?? null;

        return match ($key) {
            'label_template'  => LabelsPage::url(['etikett' => $record]),
            'label_printer'   => PrintersPage::url(['satz' => $record]),
            'label_roll'      => PrintersPage::mediaUrl(['satz' => $record]),
            'label_workplace' => WorkplacesPage::url(['satz' => $record]),
            'label_rule'      => PrintRulesSetup::url($record),
            default           => $url,
        };
    }
}
