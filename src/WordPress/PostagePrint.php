<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\Core\Exception\DomainError;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Plugin as Modeller;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\LabelPrinter;
use TaxmodLabel\Core\Pdf\PostageLabel;

/**
 * D-1517: a postage label printed by the print service on the workplace's default printer (D-1502) instead of the browser's
 * dialog. The browser crops the PDF as before (label62.js) and sends the picture here on a click; this is the one endpoint, and
 * other modules reach it only through taxmodLabel62. Without a default printer the answer says so and the browser prints.
 *
 * ```mermaid
 * flowchart LR
 *   C["click"] --> J["label62.js: PNG"] --> E["ACTION: cap → nonce → PNG"] --> Q["ModelDoor::queuePrint"]
 *   E -->|no default printer| B["browser print"]
 * ```
 *
 * ⚠️ *The job's PDF carries the receiver's address; the modeller's print-job retention (D-1307) removes finished jobs, and a
 * reprint comes from the postage store (D-1513), not from the job.*
 *
 * @see docs/konzept.md §7
 */
final class PostagePrint
{
    public const ACTION = 'taxmod_label_postage_print';

    public const NONCE = '_taxmod_postage_nonce';

    private const TITLE_MAX = 64;

    /** Only where admin-ajax.php answers (D-1187). */
    public static function wire(): void
    {
        add_action('wp_ajax_' . self::ACTION, self::handle(...));
    }

    public static function handle(): void
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            wp_send_json_error(['message' => __('You cannot print labels.', 'taxmod-label')], 403);
        }
        check_ajax_referer(self::ACTION, self::NONCE);

        $file = $_FILES['png'] ?? null;
        if (! is_array($file) || ! is_string($file['tmp_name'] ?? null) || ! is_uploaded_file($file['tmp_name']) || (int) ($file['size'] ?? 0) > PostageLabel::MAX_BYTES) {
            wp_send_json_error(['message' => ProblemWords::ofError(LabelError::notAPostagePicture('no PNG'))], 400);
        }
        $caption = mb_substr(sanitize_text_field(wp_unslash((string) ($_POST['caption'] ?? ''))), 0, self::TITLE_MAX);

        try {
            $picture = PostageLabel::picture((string) file_get_contents($file['tmp_name']));
            $printer = self::defaultPrinter();
            ['pdf' => $pdf, 'format' => $format] = PostageLabel::pdf($picture, $printer);
            $delivery = ['roll' => $format->rollMm, 'queue' => $printer->queue, 'options' => $printer->optionsFor($format), 'retry' => $printer->retries ? 1 : 0, 'pause' => $printer->pauseSeconds];
            $job = (new ModelDoor())->queuePrint($pdf, PostageLabel::FORMAT_KEY, '', $caption !== '' ? $caption : __('Porto', 'taxmod-label'), 1, $delivery);
        } catch (LabelError $refused) {
            wp_send_json_error(['message' => ProblemWords::ofError($refused), 'browser' => $refused->reason === 'no_workplace_printer'], $refused->reason === 'no_workplace_printer' ? 409 : 400);
        }

        wp_send_json_success(['job' => $job, 'message' => sprintf(/* translators: 1: job number, 2: printer */ __('Druckauftrag %1$d an %2$s gesendet.', 'taxmod-label'), $job, $printer->name !== '' ? $printer->name : $printer->queue)]);
    }

    /** @throws LabelError */
    private static function defaultPrinter(): LabelPrinter
    {
        $service = new LabelService();
        try {
            $place   = $service->workplace();
            $printer = $place === null ? null : ($service->catalog()->printerList()[$place->defaultPrinter] ?? null);
        } catch (DomainError) {
            $printer = null;
        }
        if ($printer === null) {
            throw LabelError::noWorkplacePrinter();
        }

        return LabelPrinter::fromArray($printer);
    }
}
