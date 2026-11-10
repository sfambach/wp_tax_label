<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxmod Label
 * Description:       Labels for the Taxonomy Modeller: label editor with live drawing, QR, DataMatrix, Code128, EAN-13, Code39, print dialog and abilities.
 * Version: 0.0.1
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Stefan Fambach (SteFam), Claude (Anthropic AI)
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       taxmod-label
 * GitHub Plugin URI: sfambach/wp_tax_label
 * Primary Branch:    main
 */

if (! defined('ABSPATH')) {
    exit;
}

define('TAXMOD_LABEL_VERSION', '0.0.1');

$taxmodLabelAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxmodLabelAutoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Taxmod Label: vendor/autoload.php fehlt — bitte das Release-Paket installieren.', 'taxmod-label') . '</p></div>';
    });

    return;
}

require $taxmodLabelAutoloader;

(new TaxmodLabel\WordPress\Plugin())->boot(__FILE__);
