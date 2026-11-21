<?php declare(strict_types=1);

/**
 * D-1483: a record this module owns opens on its own page — the eye at a field «Etikett» leads to the label editor, not into
 * the Taxonomy Modeller (Stefan 21.11. 15:08: «Das sollten wir überall mal lindern»).
 *
 *     php scripts/dev/record-home-check.php
 *
 * Reads the source only; needs no WordPress. Each node the module claims has an answer in RecordHome, and the answer is wired.
 */

$root  = dirname(__DIR__, 2);
$red   = 0;
$home  = (string) @file_get_contents($root . '/src/WordPress/RecordHome.php');
$wired = (string) @file_get_contents($root . '/src/WordPress/Plugin.php');

$pages = [
    'label_template'  => "LabelsPage::url(['etikett' => \$record])",
    'label_printer'   => "PrintersPage::url(['satz' => \$record])",
    'label_roll'      => "PrintersPage::mediaUrl(['satz' => \$record])",
    'label_workplace' => "WorkplacesPage::url(['satz' => \$record])",
    'label_rule'      => 'PrintRulesSetup::url($record)',
];

foreach ($pages as $key => $answer) {
    if (! str_contains($home, "'{$key}'") || ! str_contains($home, $answer)) {
        printf("FAIL %s: no page of its own in RecordHome (expected %s)\n", $key, $answer);
        $red++;
    }
}

if (! str_contains($wired, "add_filter('taxmod_record_home', RecordHome::answer(...), 10, 3)")) {
    echo "FAIL src/WordPress/Plugin.php: RecordHome does not answer taxmod_record_home\n";
    $red++;
}

echo $red === 0 ? "record-home-check: green\n" : "record-home-check: {$red} red\n";
exit($red === 0 ? 0 : 1);
