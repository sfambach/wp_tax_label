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

    public const DIALOG_ACTION = 'taxmod_label_postage_dialog';

    private const TITLE_MAX = 64;

    /** Only where admin-ajax.php answers (D-1187). */
    public static function wire(): void
    {
        add_action('wp_ajax_' . self::ACTION, self::handle(...));
        add_action('wp_ajax_' . self::DIALOG_ACTION, self::dialog(...));
    }

    /**
     * Stefan 20.11. 18:28 «eigentlich müsste ja der Druckdialog kommen … der Drucker … mit der Voransicht»: the module's print
     * dialog for a postage label (LabelButton::postageDialog). Nothing is printed until «Drucken» is pressed.
     */
    public static function dialog(): void
    {
        self::allowed();
        $printers = self::printers();
        if ($printers === []) {
            wp_send_json_error(['message' => ProblemWords::ofError(LabelError::noWorkplacePrinter()), 'browser' => true], 409);
        }

        // Prüftor 20.11.: the one print dialog of this module (D-1511 rule 3); the postage picture is one more source of its preview.
        wp_send_json_success(['html' => LabelButton::postageDialog($printers)]);
    }

    public static function handle(): void
    {
        self::allowed();

        $file = $_FILES['png'] ?? null;
        if (! is_array($file) || ! is_string($file['tmp_name'] ?? null) || ! is_uploaded_file($file['tmp_name']) || (int) ($file['size'] ?? 0) > PostageLabel::MAX_BYTES) {
            wp_send_json_error(['message' => ProblemWords::ofError(LabelError::notAPostagePicture('no PNG'))], 400);
        }
        $caption = mb_substr(sanitize_text_field(wp_unslash((string) ($_POST['caption'] ?? ''))), 0, self::TITLE_MAX);
        $wanted  = absint(wp_unslash($_POST['printer'] ?? 0));

        try {
            $picture = PostageLabel::picture((string) file_get_contents($file['tmp_name']));
            $printer = self::printer($wanted);
            ['pdf' => $pdf, 'format' => $format] = PostageLabel::pdf($picture, $printer);
            $delivery = ['roll' => $format->rollMm, 'queue' => $printer->queue, 'options' => $printer->optionsFor($format), 'retry' => $printer->retries ? 1 : 0, 'pause' => $printer->pauseSeconds];
            $job = (new ModelDoor())->queuePrint($pdf, PostageLabel::FORMAT_KEY, '', $caption !== '' ? $caption : __('Porto', 'taxmod-label'), 1, $delivery);
        } catch (LabelError $refused) {
            wp_send_json_error(['message' => ProblemWords::ofError($refused), 'browser' => $refused->reason === 'no_workplace_printer'], $refused->reason === 'no_workplace_printer' ? 409 : 400);
        }

        wp_send_json_success(['job' => $job, 'message' => sprintf(/* translators: 1: job number, 2: printer */ __('Druckauftrag %1$d an %2$s gesendet.', 'taxmod-label'), $job, $printer->name !== '' ? $printer->name : $printer->queue)]);
    }

    private static function allowed(): void
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            wp_send_json_error(['message' => __('Du darfst keine Etiketten drucken.', 'taxmod-label')], 403);
        }
        check_ajax_referer(self::ACTION, self::NONCE);
    }

    /**
     * The printers the dialog offers — the workplace's, its default printer first; without a workplace all (D-1502).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function printers(): array
    {
        try {
            return (new LabelService())->dialogCatalog()->printerList();
        } catch (DomainError) {
            return [];
        }
    }

    /**
     * The printer chosen in the dialog, if the dialog offers it.
     *
     * @throws LabelError
     */
    private static function printer(int $wanted): LabelPrinter
    {
        $printers = self::printers();
        if ($printers === []) {
            throw LabelError::noWorkplacePrinter();
        }
        if (! isset($printers[$wanted])) {
            throw LabelError::notAPrinter((string) $wanted);
        }

        return LabelPrinter::fromArray($printers[$wanted]);
    }
}
