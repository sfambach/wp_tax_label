<?php declare(strict_types=1);
/**
 * The labels check their own rights (D-1585 step 3, D-1586): printing is a cross function every role holds, setting labels,
 * printers and workplaces up is configuration, and no old right is left.
 *
 *     php scripts/dev/rights-check.php [path/to/wordpress]
 *
 * Needs the Taxonomy Modeller ab 0.2.237 active (WP_ROOT, TAXMOD_ROOT override the paths).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Rights;
use TaxmodLabel\WordPress\LabelAbilities;
use TaxmodLabel\WordPress\Plugin;

$bad = 0;
$pruefe = static function (bool $ok, string $what) use (&$bad): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $what . "\n";
    $bad += $ok ? 0 : 1;
};

$old = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $old += preg_match_all('/\'manage_options\'|\bModeller::CAPABILITY\b|Plugin::CAPABILITY\b/', (string) file_get_contents($file->getPathname()));
    }
}
$pruefe($old === 0, "no old right left in src ($old)");

$pruefe(array_unique(array_column(Plugin::pages([]), 'capability')) === [Rights::CONFIGURE], 'every page of the labels is configuration');

Rights::ensureRoles();
$user   = static fn (string $login, string $role): int => (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role]);
$stock  = $user('__lr_stock', 'taxmod_stock');
$reader = $user('__lr_reader', 'taxmod_read_only');
$admin  = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);

$may = static function (int $who, string $ability): bool {
    wp_set_current_user($who);
    $found = wp_get_ability($ability);

    return $found !== null && $found->check_permissions() === true;
};

if (! function_exists('wp_get_ability')) {
    $pruefe(false, 'the abilities API is there');
} else {
    foreach (['Lager' => $stock, 'Nur lesen' => $reader] as $name => $who) {
        $pruefe($may($who, LabelAbilities::PRINT) && $may($who, LabelAbilities::PREVIEW) && $may($who, LabelAbilities::READ), "$name: prints, draws and reads labels (cross function)");
        $pruefe(! $may($who, LabelAbilities::SAVE) && ! $may($who, LabelAbilities::REMOVE), "$name: changes no label");
    }
    $pruefe($may($admin, LabelAbilities::SAVE) && $may($admin, LabelAbilities::REMOVE), 'the administrator changes labels');
}

echo $bad === 0 ? "\nAll green.\n" : "\n$bad failed.\n";
exit($bad === 0 ? 0 : 1);
