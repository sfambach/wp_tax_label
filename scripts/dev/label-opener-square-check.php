<?php declare(strict_types=1);

/**
 * The label opener in a row is a square like every other row button (D-1511 rule 3; Live-Check 7, 21.11.2026: 51 px of 706).
 *
 *     php scripts/dev/label-opener-square-check.php
 *
 * Reads the source only; needs no WordPress.
 */

$button = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/LabelButton.php');
$green  = str_contains($button, "'button taxmod-square taxmod-dialog-open',")
    && str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/PrintRulesSetup.php'), "'secondary' => array_values(");

echo $green ? "label-opener-square-check: green\n" : "FAIL the label opener is no square\nlabel-opener-square-check: 1 red\n";
exit($green ? 0 : 1);
