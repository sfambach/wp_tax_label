<?php declare(strict_types=1);

/**
 * The print dialog of a list row against a local WordPress: a dialog that offers the catalog's own labels carries no filled
 * document and no printer — and what the server fills when one is printed is, value for value, the document the dialog
 * posted before (D-1494). A host with a template of its own keeps posting its documents (D-1153, D-1292), and the printer is
 * taken from the catalog of the person's workplace (D-1502).
 *
 *     php scripts/dev/label-button-check.php [path/to/wordpress]
 *
 * Reads nothing from the model and writes nothing: the catalog is a made-up one, put in the button's place for the request.
 * Needs the Taxonomy Modeller active (WP_ROOT overrides the path).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use TaxmodLabel\Core\LabelCatalog;
use TaxmodLabel\WordPress\LabelButton;

wp_set_current_user(1);

$failed = 0;
$claim  = static function (string $what, bool $holds) use (&$failed): void {
    echo ($holds ? 'ok    ' : 'FAIL  ') . $what . "\n";
    $failed += $holds ? 0 : 1;
};

$printer = static fn (string $queue, int $roll, string $name, array $allows): array => ['queue' => $queue, 'roll' => $roll, 'turn' => false, 'options' => 'media=Custom.62x30mm', 'turn_options' => '', 'sizes' => '', 'offset' => 0, 'line' => 0, 'retry' => true, 'dpi' => 300, 'pause' => 0, 'name' => $name, 'roll_name' => $roll . ' mm', 'allows' => $allows];
$printers = [21 => $printer('breit', 62, 'Breit', [11, 12]), 22 => $printer('schmal', 12, 'Schmal', [13])];
$layouts  = [
    11 => ['62×30', ['key' => 'material62', 'w' => 62, 'h' => 30, 'roll' => 62], '[{"type":"qr","field":"code","x":3,"y":3,"w":24,"h":24},{"type":"text","field":"title","x":30,"y":3,"w":29,"h":12,"pt":28,"bold":true,"lines":2},{"type":"text","field":"Charge|subtitle","x":30,"y":16,"w":29,"h":4.5,"pt":10}]'],
    12 => ['62×50', ['key' => 'material62x50', 'w' => 62, 'h' => 50, 'roll' => 62], '[{"type":"text","field":"title","x":3,"y":3,"w":56,"h":12,"pt":24,"bold":true,"lines":2},{"type":"qr","field":"code","x":3,"y":16,"w":31,"h":31},{"type":"text","field":"Beschreibung","x":36,"y":26,"w":23,"h":13,"pt":9,"lines":4}]'],
    13 => ['40×12', ['key' => 'x1smd40', 'w' => 40, 'h' => 12, 'roll' => 12], '[{"type":"qr","field":"code","x":2.5,"y":1,"w":10,"h":10},{"type":"text","field":"title","x":13.5,"y":0.5,"w":26,"h":7.3,"pt":10,"bold":true,"lines":2}]'],
];
$templates = [];

foreach ($layouts as $id => [$name, $format, $layout]) {
    $templates[$id] = ['name' => $name, 'format' => $format, 'copies' => 1, 'layout' => $layout, 'category' => 'Material', 'node' => 7];

    foreach ($printers as $one) {
        if (in_array($id, $one['allows'], true)) {
            $templates[$id]['printer'] = array_diff_key($one, ['allows' => 1, 'roll_name' => 1]);
            break;
        }
    }
}

$catalog = new LabelCatalog($templates, $printers);
$button  = new ReflectionClass(LabelButton::class);
$as      = static function (?LabelCatalog $which) use ($button): void {
    $button->getProperty('catalog')->setValue(null, $which);
    $button->getProperty('words')->setValue(null, []);
};
/** @return array<string, list<string>|string> the hidden inputs of a button, as a browser posts them */
$posted = static function (string $html): array {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $pairs = [];

    foreach ($dom->getElementsByTagName('input') as $input) {
        if ($input->getAttribute('type') === 'hidden') {
            $pairs[] = rawurlencode($input->getAttribute('name')) . '=' . rawurlencode($input->getAttribute('value'));
        }
    }

    parse_str(implode('&', $pairs), $post);

    return $post;
};
$title  = 'Widerstand 10 kΩ "0603" & <b>Größe</b>';
$more   = ['record' => 4711, 'values' => ['Charge' => 'Charge: 30739 "A" & B', 'Beschreibung' => 'Dickschicht, 1/8 W']];
$draw   = static fn (array $template): string => (string) apply_filters(LabelButton::FILTER, '', 'P', 815, $title, 'Unterzeile', 1, $template);
$dialog = $catalog->dialog(11, $more);

// 1 — the old way, as a host with a catalog this request does not know: every label filled into the row.
$as(new LabelCatalog());
$before = $draw($dialog);
$old    = $posted($before);
$claim('ein Dialog fremder Etiketten trägt je Etikett ein Dokument (D-1153, wie bisher)', count((array) ($old['documents'] ?? [])) === 3 && count((array) ($old['printers'] ?? [])) === 2);

// 2 — the catalog's own labels: the row carries no document.
$as($catalog);
$after = $draw($dialog);
$new   = $posted($after);
$claim('ein Dialog der Katalog-Etiketten trägt kein Dokument und keinen Drucker', ! isset($new['documents']) && ! isset($new['printers']) && ! str_contains($after, 'documents[') && ! str_contains($after, 'printers['));
$claim('er nennt weiter Satz, Code und die Werte des Moduls', ($new['record'] ?? '') === '4711' && ($new['code'] ?? '') === 'P-815' && ($new['value_fields'] ?? []) === ['Charge', 'Beschreibung'] && ($new['value_texts'] ?? []) === array_values($more['values']));
$claim('die Auswahl bietet dieselben Etiketten und Drucker wie zuvor', preg_match_all('/<option[^>]*>[^<]*<\/option>/', $before, $a) === preg_match_all('/<option[^>]*>[^<]*<\/option>/', $after, $b) && $a[0] === $b[0]);
$claim('der Knopf ist höchstens halb so groß wie zuvor', strlen($after) * 2 <= strlen($before));

// 3 — what the server fills is the document the dialog posted before, for every label.
$fill = $button->getMethod('documentOf');
$same = true;

foreach (array_keys($layouts) as $id) {
    $same = $same && $fill->invoke(null, $catalog, $id, $new) === json_decode((string) $old['documents'][$id], true);
}

$claim('beim Drucken füllt der Server dasselbe Dokument, Wert für Wert', $same);
$claim('ein Etikett, das der Katalog nicht kennt, ergibt kein Dokument', $fill->invoke(null, $catalog, 99, $new) === null);

// 4 — the second row of a list reads its words from the first and is the same button.
$claim('eine zweite Zeile ergibt denselben Knopf', $posted($draw($dialog)) === $new);

// 5 — anything that is not the catalog's falls back to documents.
$own = $dialog;
$own['choices'][11]['layout'] = '[{"type":"text","field":"title","x":1,"y":1,"w":20,"h":5,"pt":9}]';
$claim('ein Etikett mit eigenem Layout wird weiter als Dokument gepostet', isset($posted($draw($own))['documents']));

$other = $dialog;
unset($other['printers'][22]);
$claim('andere Drucker als die des Katalogs: weiter als Dokument', isset($posted($draw($other))['documents']));

$mixed = $dialog;
$mixed['choices'][12]['values'] = ['Charge' => 'eine andere'];
$claim('Etiketten mit verschiedenen Werten: weiter als Dokument', isset($posted($draw($mixed))['documents']));

// 6 — D-1502: the server takes the printer from the catalog as the person's workplace sees it.
$as($catalog);
$pick = $button->getMethod('printerOf');
$claim('der Server nimmt den Drucker aus dem Katalog, nur wenn dessen Rolle das Etikett erlaubt (D-1192)', ($pick->invoke(null, $catalog, 21, 11)['queue'] ?? '') === 'breit' && $pick->invoke(null, $catalog, 21, 13) === null && $pick->invoke(null, $catalog, 77, 11) === null);
$place = $catalog->at(new TaxmodLabel\Core\Workplace(1, 'Werkbank', [22], 22));
$claim('ein Drucker außerhalb des Arbeitsplatzes wird abgelehnt, auch wenn er gepostet wird (D-1502)', $pick->invoke(null, $place, 21, 11) === null && ($pick->invoke(null, $place, 22, 13)['queue'] ?? '') === 'schmal');
$as($place);
$atPlace = $draw($place->dialog(11, $more));
$claim('am Arbeitsplatz bleibt der Dialog schlank und bietet nur dessen Drucker', ! str_contains($atPlace, 'documents[') && str_contains($atPlace, 'Schmal') && ! str_contains($atPlace, 'Breit'));
$claim('ein Dialog aus dem Katalog ohne Arbeitsplatz postet dort weiter Dokumente (anderer Druckerbestand)', isset($posted($draw($dialog))['documents']));

$as(null);

// 7 — Prüftor 21.11. (INF-137): no print rule holds — the dialog says so and links to the rules; without the mark it does not.
$none = $draw(['no_rule' => true]);
$claim('ohne greifende Druckregel nennt der Dialog das und verlinkt die Druckregeln', str_contains($none, 'Keine Druckregel greift') && str_contains($none, 'notice-warning') && str_contains($none, 'taxmod-regeln'));
$claim('ohne die Marke kein solcher Hinweis', ! str_contains($draw([]), 'Keine Druckregel greift'));

echo $failed === 0 ? "\nlabel-button-check: all claims hold\n" : "\nlabel-button-check: {$failed} claim(s) FAILED\n";
exit($failed === 0 ? 0 : 1);
