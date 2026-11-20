<?php declare(strict_types=1);

/**
 * D-1541: no first-match of its own in this module — which print rule holds is asked of the modeller's one rule book
 * (`ModelDoor::firstRule`), and the core only translates rules and context for it.
 *
 *     php scripts/dev/rule-book-use-check.php
 *
 * Reads the source only; needs no WordPress.
 */

$root = dirname(__DIR__, 2);
$red  = 0;
$own  = '/function\s+holdsFor\s*\(|function\s+first\s*\(\s*PrintContext|->holdsFor\s*\(/';

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (substr((string) $file, -4) === '.php' && preg_match($own, (string) file_get_contents((string) $file)) === 1) {
        printf("FAIL %s: decides itself which rule holds — that is ModelDoor::firstRule (D-1541)\n", substr((string) $file, strlen($root) + 1));
        $red++;
    }
}
if (! str_contains((string) file_get_contents($root . '/src/WordPress/LabelService.php'), '->firstRule(')) {
    echo "FAIL src/WordPress/LabelService.php: the print rules no longer reach ModelDoor::firstRule (D-1541)\n";
    $red++;
}

echo $red === 0 ? "rule-book-use-check: green\n" : "rule-book-use-check: {$red} red\n";
exit($red === 0 ? 0 : 1);
