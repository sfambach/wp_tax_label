<?php declare(strict_types=1);

/*
 * Writes a PDF with every code type at a size near its minimum, for scripts/dev/scan-check.py to read back.
 * Usage: php scripts/dev/scan-sheet.php out.pdf [dpi]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use TaxmodLabel\Core\Drawing\Resolution;
use TaxmodLabel\Core\LabelDocument;
use TaxmodLabel\Core\LabelFormat;
use TaxmodLabel\Core\LabelLayout;

$out = $argv[1] ?? 'scan-sheet.pdf';
$dpi = (float) ($argv[2] ?? Resolution::DEFAULT_DPI);

$layout = LabelLayout::fromJson((string) json_encode([
    ['type' => 'qr', 'field' => 'code', 'x' => 2.3, 'y' => 2.1, 'w' => 12],
    ['type' => 'datamatrix', 'field' => 'code', 'x' => 16.1, 'y' => 2.1, 'w' => 8],
    ['type' => 'code128', 'field' => 'code', 'x' => 26.7, 'y' => 2.1, 'w' => 40, 'h' => 12],
    ['type' => 'code39', 'field' => 'code', 'x' => 2.3, 'y' => 16.2, 'w' => 64, 'h' => 12],
    ['type' => 'ean13', 'field' => 'ean', 'x' => 2.3, 'y' => 30.4, 'w' => 46, 'h' => 14],
]));

file_put_contents($out, LabelDocument::renderLayout(new LabelFormat('sheet', 70, 46, 70, false), $layout, [['code' => 'M-45704', 'ean' => '400638133393']], new Resolution($dpi)));
