<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use TaxmodLabel\Core\Pdf\PostageText;

/**
 * D-1507: the one place the 62 mm postage print (pdf.js, crop, print in the browser) lives; D-1511: and the reading of a postage PDF's
 * text, in the browser (taxmodLabel62.lines) and on the server (TEXT_FILTER). Other modules attach it by HANDLE only;
 * when this module is missing the handle is not registered and they leave their button out.
 *
 * @see docs/konzept.md §7
 */
final class Label62Script
{
    public const HANDLE = 'taxmod-label62';

    public const PDFJS_HANDLE = 'taxmod-pdfjs';

    /** D-1511: other modules read a postage PDF's text lines here — apply_filters(TEXT_FILTER, [], $pdfBytes) → list<string>. */
    public const TEXT_FILTER = 'taxmod_label_pdf_text';

    /** Shipped in assets/pdfjs, never fetched from a CDN at runtime. */
    public const PDFJS_VERSION = '3.11.174';

    public static function boot(): void
    {
        add_action('init', self::register(...));
        add_filter(self::TEXT_FILTER, self::text(...), 10, 2);
    }

    /**
     * @param mixed $lines what an earlier filter answered; `mixed`: a filter carries anything
     * @return list<string>
     */
    public static function text(mixed $lines, mixed $pdf = ''): array
    {
        return is_string($pdf) && $pdf !== '' ? PostageText::of($pdf) : (is_array($lines) ? array_values(array_map('strval', $lines)) : []);
    }

    public static function register(): void
    {
        wp_register_script(self::PDFJS_HANDLE, Plugin::url('assets/pdfjs/pdf.min.js'), [], self::PDFJS_VERSION, true);
        wp_register_script(self::HANDLE, Plugin::url('assets/label62.js'), [self::PDFJS_HANDLE], Plugin::VERSION, true);
        wp_localize_script(self::HANDLE, 'taxmodLabel62', [
            'worker' => Plugin::url('assets/pdfjs/pdf.worker.min.js'),
            // Postage PDFs use fonts they do not embed; without these pdf.js leaves that text out.
            'cmaps'  => Plugin::url('assets/pdfjs/cmaps/'),
            'fonts'  => Plugin::url('assets/pdfjs/standard_fonts/'),
            'empty'  => __('The PDF has no printed content.', 'taxmod-label'),
            'failed' => __('The PDF could not be read:', 'taxmod-label'),
            // D-1517: the print service on the workplace's default printer; the browser's dialog only where that is missing.
            'service' => current_user_can(\Taxmod\WordPress\Rights::VIEW) ? [
                'url'         => admin_url('admin-ajax.php'),
                'action'      => PostagePrint::ACTION,
                'dialog'      => PostagePrint::DIALOG_ACTION,
                'nonceField'  => PostagePrint::NONCE,
                'nonce'       => wp_create_nonce(PostagePrint::ACTION),
                'status'      => LabelButton::STATUS_ACTION,
                'statusField' => LabelButton::NONCE,
                'statusNonce' => wp_create_nonce(LabelButton::ACTION),
                'sending'     => __('Wird an den Drucker gesendet …', 'taxmod-label'),
            ] : null,
        ]);
    }
}
