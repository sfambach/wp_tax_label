<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\WordPress\Plugin as Modeller;
use TaxmodLabel\Core\LabelContent;
use TaxmodLabel\Core\LabelDocument;
use TaxmodLabel\Core\LabelFormat;

/**
 * Hands a label to the browser as a PDF, to print through the print dialog on a local printer (D-1032, stage 1).
 *
 * A module builds the link with {@see self::url()} from a code and two lines of text, so the
 * modeller does not need to know what a place or a box is. `test=1` prints the sample label (D-1096).
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1032, D-1292
 */
final class LabelPrint
{
    public const ACTION = 'taxmod_label_pdf';

    private const NONCE = '_taxmod_nonce';

    public static function boot(): void
    {
        add_action('admin_post_' . self::ACTION, static fn () => (new self())->handle());
    }

    /** Link that opens the label PDF in a new tab; escape it on output. */
    public static function url(string $format, ?LabelContent $label = null): string
    {
        $arguments = ['action' => self::ACTION, 'format' => $format];
        if ($label === null) {
            $arguments['test'] = '1';
        } else {
            $arguments += ['code' => $label->code, 'title' => $label->title, 'subtitle' => $label->subtitle];
        }
        $arguments[self::NONCE] = wp_create_nonce(self::ACTION);
        return add_query_arg(array_map('rawurlencode', $arguments), admin_url('admin-post.php'));
    }

    public function handle(): void
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            wp_die(esc_html__('You cannot print labels.', 'taxmod-label'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION, self::NONCE);

        $pdf = $this->pdfFor(wp_unslash($_GET));
        if ($pdf instanceof \WP_Error) {
            wp_die(esc_html($pdf->get_error_message()), '', ['response' => 400]);
        }
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : 'test';

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . sanitize_file_name('label-' . $code . '.pdf') . '"');
        header('Content-Length: ' . (string) strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF, not HTML.
        exit;
    }

    /**
     * Validate and sanitise the query, then lay out the label; capability and nonce are the caller's.
     *
     * @param array<string, mixed> $query unslashed request values
     */
    public function pdfFor(array $query): string|\WP_Error
    {
        $format  = sanitize_key((string) ($query['format'] ?? ''));
        $formats = LabelFormat::shipped();
        if (! isset($formats[$format])) {
            return new \WP_Error('taxmod_label_format', __('Unknown label format.', 'taxmod-label'));
        }

        $label = isset($query['test']) ? LabelContent::sample() : self::labelFrom($query);
        if ($label === null) {
            return new \WP_Error('taxmod_label_content', __('A label needs a code like O-123 and a title.', 'taxmod-label'));
        }

        try {
            return LabelDocument::render($formats[$format], [$label]);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('taxmod_label_layout', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function labelFrom(array $query): ?LabelContent
    {
        $field = static fn (string $key): string => sanitize_text_field((string) ($query[$key] ?? ''));
        $code  = $field('code');
        $title = $field('title');
        // D-1223: every prefix is a label code — the lot's P- was refused here, so its preview and print failed (2026-11-08).
        if (! LabelPrefix::isCode($code) || $title === '') {
            return null;
        }
        return new LabelContent($code, $title, $field('subtitle'));
    }
}
