<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\Core\Printing\PrintJobStatus;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Plugin as Modeller;
use Taxmod\WordPress\Printing\PrintService;
use TaxmodLabel\Core\FilledLabel;
use TaxmodLabel\Core\LabelContent;
use TaxmodLabel\Core\LabelDocument;
use TaxmodLabel\Core\LabelError;
use TaxmodLabel\Core\LabelFormat;
use TaxmodLabel\Core\LabelProblems;
use TaxmodLabel\Core\LabelTemplate;

/**
 * «Etikett drucken» for any module: the filter {@see self::FILTER} returns a button that opens the shared dialog — choose the
 * format, then send it to the print service or open it as PDF. One button for every module, so a place, a box or a material
 * prints the same way (D-1120).
 *
 * The button brings its own form; place it outside any other form.
 *
 * ⚠️ *D-1292: moved here from the modeller. A host page that asks the filter while this module is off shows no button.*
 *
 * D-1292: a host may pass only `label` (the label record it decided on, 0: the first of `category`) and `category` (the node of
 * «Etiketten» it prints from); the dialog then offers that category's labels and the printers whose roll allows them.
 *
 * @see docs/konzept.md §7
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1120, D-1292
 */
final class LabelButton
{
    /**
     * Filter: `(string $html, string $prefix 'O'|'M'|'E'|'K', int $id, string $title, string $subtitle, int $copies = 1, array $template = []): string`.
     *
     * D-1153: `$template` = `['name' => 'Platz', 'format' => ['key', 'w', 'h', 'roll'], 'layout' => JSON, 'values' => [field ⇒ text]]` —
     * the label is then drawn from that layout; `code`, `title` and `subtitle` are among its values.
     * D-1191: `'printer' => ['queue', 'roll', 'turn', 'options', 'turn_options', 'sizes', 'offset', 'line', 'name']` — the printer
     * whose roll the label fits; the job then carries its queue and the CUPS options for this label to the print service.
     * D-1192: `'choices' => [id => template]`, `'chosen' => id`, `'printers' => [id => printer + 'allows' => [template ids]]` —
     * the dialog offers the labels to choose from, the chosen one first, and the printers whose loaded roll allows it.
     * D-1160: with `'record' => id` the modeller reads the fields the layout names from that record itself; what the module
     * passes in `values` only fills what the record has no field for.
     */
    public const FILTER = 'taxmod_label_button';

    public const ACTION = 'taxmod_label_queue';

    private const NONCE = '_taxmod_label_nonce';

    /** D-1221: the dialog asks for the state of the job it sent until the service has printed it. */
    public const STATUS_ACTION = 'taxmod_label_job_status';

    /** The format a label of this kind starts with in the dialog. */
    private const DEFAULT_FORMAT = ['O' => 'holder62', 'K' => 'roll62', 'M' => 'material62', 'E' => 'material62', 'P' => 'material62'];

    /** D-1207: the option switching the preview in the dialog off; on unless set to '0'. */
    public const PREVIEW_OPTION = 'taxmod_label_preview_in_dialog';

    /** More copies than this in one go is a typo, not a wish. */
    public const MAX_COPIES = 50;

    public static function boot(): void
    {
        add_filter(self::FILTER, static fn (mixed $html, string $prefix = '', int $id = 0, string $title = '', string $subtitle = '', int $copies = 1, array $template = []): string => self::render($prefix, $id, $title, $subtitle, $copies, $template), 20, 7);
    }

    /** Only where admin-post.php and admin-ajax.php answer (D-1187). */
    public static function wireActions(): void
    {
        add_action('admin_post_' . self::ACTION, self::handle(...));
        add_action('wp_ajax_' . self::STATUS_ACTION, self::status(...));
    }

    /** @param array<string, mixed> $template */
    public static function render(string $prefix, int $id, string $title, string $subtitle = '', int $copies = 1, array $template = []): string
    {
        $kind = LabelPrefix::tryFrom($prefix);
        if ($kind === null || $id <= 0 || trim($title) === '' || ! current_user_can(Modeller::CAPABILITY)) {
            return '';
        }
        do_action(Modeller::STYLE_ACTION);

        $label    = new LabelContent($kind->code($id), $title, $subtitle);
        $template = self::expanded($template);
        $chooser = self::chooser($label, $template);
        $filled = $chooser === null ? self::filled($label, $template) : null;
        $default = self::DEFAULT_FORMAT[$kind->value];
        $options = '';
        foreach (LabelFormat::shipped() as $format) {
            $options .= '<option value="' . esc_attr($format->key) . '"' . selected($format->key, $default, false) . '>'
                . esc_html(self::size($format)) . '</option>';
        }
        $service = self::serviceIsSetUp();
        $switch = 'taxmod-label-' . strtolower($kind->value) . '-' . $id;

        // His wish 2026-11-07: «vorschaubild im druckdialog nach rechts zumindest für desktop» — options left, preview right; on a
        // narrow screen the preview stays below (assets/admin.css).
        $body = '<span class="taxmod-label-dialog"><span class="taxmod-label-dialog-options">' . ($chooser !== null ? $chooser['html'] : ($filled === null
                ? '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Format', 'taxmod-label') . '</span>'
                    . '<select name="format">' . $options . '</select></label>'
                : '<span style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Vorlage', 'taxmod-label') . '</span>'
                    . esc_html(trim($filled->templateName . ' · ' . self::size($filled->format) . ($filled->printer === null ? '' : ' · ' . ($filled->printer->name !== '' ? $filled->printer->name : $filled->printer->queue)), ' ·')) . '</span>'))
            // D-1152, his word 2026-11-04: «Bei etikett druck anzahl mit aufnehmen» — the module says how many to start with.
            . '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Anzahl', 'taxmod-label') . '</span>'
            . \Taxmod\Core\Renderer\DialogNumber::input('copies', self::copies($copies), 1, self::MAX_COPIES) . '</label>'
            . '<span style="display:block">' . esc_html($label->code . ' · ' . $label->title . ($label->subtitle === '' ? '' : ' · ' . $label->subtitle)) . '</span>'
            . ($service ? '' : '<span class="description" style="display:block;margin-top:.5em">' . esc_html__('No print service set up: the label opens as PDF to print from the browser.', 'taxmod-label') . '</span>')
            . '</span>' . self::previewBox() . '</span>';

        $foot = ($service ? '<button type="submit" class="button button-primary" name="how" value="service">' . esc_html__('Print', 'taxmod-label') . '</button>' : '')
            . '<button type="submit" class="button' . ($service ? '' : ' button-primary') . '" name="how" value="pdf" formtarget="_blank">' . esc_html__('Open as PDF', 'taxmod-label') . '</button>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . wp_nonce_field(self::ACTION, self::NONCE, true, false)
            . '<input type="hidden" name="code" value="' . esc_attr($label->code) . '">'
            . '<input type="hidden" name="title" value="' . esc_attr($label->title) . '">'
            . '<input type="hidden" name="subtitle" value="' . esc_attr($label->subtitle) . '">'
            . ($filled === null ? '' : '<input type="hidden" name="document" value="' . esc_attr($filled->toJson()) . '">')
            . ($chooser === null ? '' : $chooser['hidden'])
            . \Taxmod\Core\Renderer\DialogMarkup::of(
                $switch,
                // D-1230: the printer instead of the word, in every module that asks for this button.
                ListPage::icon(ListPage::ICON_PRINT, __('Etikett drucken', 'taxmod-label')),
                esc_html__('Print a label', 'taxmod-label'),
                '<span class="taxmod-dialog-body" style="display:block;padding:.75em 1em;white-space:normal;text-align:left">' . $body . '</span>',
                $foot,
                'button taxmod-dialog-open',
                '',
                __('Cancel', 'taxmod-label')
            )
            . '</form>'
            . self::sendScript();
    }

    /** admin-post: capability, nonce, validate, then queue for the service or hand out the PDF. */
    public static function handle(): void
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            self::fail(esc_html__('You cannot print labels.', 'taxmod-label'), 403, ['taxmod_ajax' => $_POST['taxmod_ajax'] ?? '']);
        }
        check_admin_referer(self::ACTION, self::NONCE);

        $post = wp_unslash($_POST);
        $copies = self::copies((int) ($post['copies'] ?? 1));

        // D-1192: the label and the printer chosen in the dialog; a printer whose roll does not allow the label is refused.
        if (isset($post['choice'])) {
            $choice   = absint($post['choice']);
            $document = json_decode((string) (((array) ($post['documents'] ?? []))[$choice] ?? ''), true);
            $printer  = (string) ($post['printer'] ?? '');
            if (! is_array($document)) {
                self::fail(esc_html__('Unknown label.', 'taxmod-label'), 400, $post ?? []);
            }
            if ($printer !== '') {
                $raw = json_decode((string) (((array) ($post['printers'] ?? []))[absint($printer)] ?? ''), true);
                if (! is_array($raw) || ! in_array($choice, array_map('intval', (array) ($raw['allows'] ?? [])), true)) {
                    self::fail(esc_html__('This printer has no roll for this label.', 'taxmod-label'), 400, $post ?? []);
                }
                $document['printer'] = $raw;
            }
            // D-1199: the text chosen for the variant field, as the dialog showed it for this label.
            $field = (string) ($post['variant_field'] ?? '');
            $texts = (array) (((array) ($post['variants'] ?? []))[$choice] ?? []);
            if (preg_match('/^[a-z0-9_]{1,32}$/', $field) === 1 && isset($post['variant']) && array_key_exists((string) $post['variant'], $texts)) {
                $document['values'] = (is_array($document['values'] ?? null) ? $document['values'] : []);
                $document['values'][$field] = sanitize_text_field((string) $texts[(string) $post['variant']]);
            }
            // The record's own words for the fields the layout names, read now (see chooser()); they win, as in filled().
            $record = absint($post['record'] ?? 0);
            if ($record > 0) {
                $fields = self::layoutFields((array) ($document['layout'] ?? []));
                $document['values'] = self::recordValues($record, $fields)['values'] + (is_array($document['values'] ?? null) ? $document['values'] : []);
            }
            $post['document'] = (string) json_encode($document);
        }

        // D-1153: a label from a template brings its own size and layout.
        if (is_string($post['document'] ?? null) && $post['document'] !== '') {
            try {
                $filled = FilledLabel::fromJson($post['document']);
            } catch (\InvalidArgumentException $e) {
                self::fail(esc_html($e->getMessage()), 400, $post ?? []);
            }
            $label = LabelPrint::labelFrom($filled->values);
            if ($label === null) {
                self::fail(esc_html__('A label needs a known format, a code like O-123 and a title.', 'taxmod-label'), 400, $post ?? []);
            }
            $pictures = LabelService::pictures($filled->layout->media());
            if (($post['how'] ?? '') === 'service' && self::serviceIsSetUp()) {
                $id = self::queue($filled, $label, $copies, $pictures, $post);
                self::answerQueued($post, $id);
                wp_safe_redirect(add_query_arg('taxmod_label_job', $id, wp_get_referer() ?: admin_url('admin.php')));
                exit;
            }
            self::sendPdf(self::drawn(static fn (): string => $filled->render($copies, ! empty($post['preview']), null, $pictures), $post), $label->code);
        }

        $formats = LabelFormat::shipped();
        $format = $formats[sanitize_key((string) ($post['format'] ?? ''))] ?? null;
        $label = LabelPrint::labelFrom($post);
        if ($format === null || $label === null) {
            self::fail(esc_html__('A label needs a known format, a code like O-123 and a title.', 'taxmod-label'), 400, $post ?? []);
        }

        if (($post['how'] ?? '') === 'service' && self::serviceIsSetUp()) {
            $pdf = self::drawn(static fn (): string => LabelDocument::render($format, array_fill(0, $copies, $label)), $post);
            $id  = self::queuePdf($pdf, $format, $label, $copies, null);
            self::answerQueued($post, $id);
            wp_safe_redirect(add_query_arg('taxmod_label_job', $id, wp_get_referer() ?: admin_url('admin.php')));
            exit;
        }

        self::sendPdf(self::drawn(static fn (): string => LabelDocument::render($format, array_fill(0, $copies, $label)), $post), $label->code);
    }

    /**
     * D-1294: the label drawn here, all copies as pages, and handed to the modeller's print jobs with the printer's queue and
     * options — the print service prints the PDF as it is.
     *
     * @param array<int, \TaxmodLabel\Core\Drawing\Picture> $pictures
     * @param array<string, mixed>                             $post
     */
    public static function queue(FilledLabel $filled, LabelContent $label, int $copies, array $pictures, array $post = []): int
    {
        $pdf = self::drawn(static fn (): string => $filled->render($copies, false, null, $pictures), $post);

        return self::queuePdf($pdf, $filled->format, $label, $copies, $filled->printer, $filled->templateName);
    }

    private static function queuePdf(string $pdf, LabelFormat $format, LabelContent $label, int $copies, ?\TaxmodLabel\Core\LabelPrinter $printer, string $name = ''): int
    {
        $delivery = ['roll' => $format->rollMm] + ($printer === null ? [] : ['queue' => $printer->queue, 'options' => $printer->optionsFor($format), 'retry' => $printer->retries ? 1 : 0]);

        return (new ModelDoor())->queuePrint($pdf, $name !== '' ? $name : $format->key, $label->code, $label->title, $copies, $delivery);
    }

    /**
     * A label that cannot be printed as it stands (a code too small to scan, a value a code cannot take) is refused with every
     * reason, never printed half-right.
     *
     * @param \Closure(): string    $draw
     * @param array<string, mixed> $post
     */
    private static function drawn(\Closure $draw, array $post): string
    {
        try {
            return $draw();
        } catch (LabelProblems $e) {
            self::fail(esc_html(implode(' ', array_map(static fn (array $p): string => $p['text'], ProblemWords::list($e->problems)))), 400, $post);
        }
    }

    /**
     * D-1292: a host that names only the label it decided on (and its category) gets the dialog built here: that category's labels,
     * the chosen one first, and the printers whose loaded roll allows them. A host that passes a whole template keeps it.
     *
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    public static function expanded(array $template): array
    {
        if (! isset($template['label']) && ! isset($template['category'])) {
            return $template;
        }
        try {
            $catalog = (new LabelService())->catalog();
        } catch (LabelError) {
            return [];
        }
        $category = absint($template['category'] ?? 0);
        $wanted   = absint($template['label'] ?? 0);
        $chosen   = $category > 0 ? $catalog->preferredIn($category, $wanted) : $wanted;
        $more     = array_diff_key($template, ['label' => 1, 'category' => 1, 'contents' => 1]);
        $contents = is_array($template['contents'] ?? null) ? $template['contents'] : [];

        return $chosen > 0 ? $catalog->dialog($chosen, $more, $contents) : [];
    }

    private static function serviceIsSetUp(): bool
    {
        return class_exists(PrintService::class) && PrintService::isSetUp();
    }

    /** «62 × 30 mm» */
    public static function size(LabelFormat $format): string
    {
        $mm = static fn (float $v): string => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');

        return $mm($format->widthMm) . ' × ' . $mm($format->heightMm) . ' mm';
    }

    /**
     * His word 2026-11-06: «lädt er danach die seite neu nur um eine meldung anzuzeigen … wäre auch gut wenn er deswegen die seite
     * nicht neuladen muss» — a print sent from the dialog's script is answered with the message alone.
     *
     * @param array<string, mixed> $post
     */
    private static function answerQueued(array $post, int $job): void
    {
        if (! empty($post['taxmod_ajax'])) {
            wp_send_json_success(['message' => sprintf(/* translators: %d: job number */ __('Job %d is waiting for the print service.', 'taxmod-label'), $job), 'job' => $job]);
        }
    }

    /**
     * D-1221, his word 2026-11-07: «gedruckt ist es schon» while the page still said the job was waiting — admin-ajax: capability,
     * nonce, then the state of one job and the message to show once it has an end.
     */
    public static function status(): void
    {
        if (! current_user_can(Modeller::CAPABILITY)) {
            wp_send_json_error(['message' => __('You cannot print labels.', 'taxmod-label')], 403);
        }
        check_ajax_referer(self::ACTION, self::NONCE);

        $job   = absint(wp_unslash($_POST['job'] ?? 0));
        $door  = new ModelDoor();
        $found = $job > 0 && method_exists($door, 'printStatus') ? $door->printStatus($job) : null;
        if ($found === null) {
            wp_send_json_error(['message' => __('The print job is gone.', 'taxmod-label')], 404);
        }

        [$state, $note] = $found;
        $message = match ($state) {
            PrintJobStatus::Printed   => sprintf(/* translators: %d: job number */ __('Job %d is printed.', 'taxmod-label'), $job),
            PrintJobStatus::Failed    => sprintf(/* translators: 1: job number, 2: what the service said */ __('Job %1$d failed: %2$s', 'taxmod-label'), $job, $note),
            PrintJobStatus::Cancelled => sprintf(/* translators: %d: job number */ __('Job %d was cancelled.', 'taxmod-label'), $job),
            default                   => '',
        };
        // D-1234: while CUPS holds it, what CUPS says — shown, and the dialog keeps watching.
        $waiting = $state === PrintJobStatus::Sent && $note !== '' ? sprintf(/* translators: 1: job number, 2: what CUPS said */ __('Job %1$d at the printer: %2$s', 'taxmod-label'), $job, $note) : '';
        wp_send_json_success(['status' => $state->value, 'done' => $message !== '', 'ok' => $state === PrintJobStatus::Printed, 'message' => $message, 'waiting' => $waiting]);
    }

    /** @param array<string, mixed> $post */
    private static function fail(string $message, int $status, array $post): never
    {
        if (! empty($post['taxmod_ajax'])) {
            wp_send_json_error(['message' => wp_strip_all_tags($message)], $status);
        }
        wp_die($message, '', ['response' => $status]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
    }

    private static function sendPdf(string $pdf, string $code): never
    {
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . sanitize_file_name('label-' . $code . '.pdf') . '"');
        header('Content-Length: ' . (string) strlen($pdf));
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF, not HTML.
        exit;
    }

    /**
     * D-1153: the module's template filled with this label's values, or null for the fixed layout of the chosen format.
     *
     * @param array<string, mixed> $template
     */
    public static function filled(LabelContent $label, array $template): ?FilledLabel
    {
        if ($template === []) {
            return null;
        }
        $values = LabelTemplate::valuesOf($label);
        $record = (int) ($template['record'] ?? 0);
        if ($record > 0) {
            $layout = json_decode((string) ($template['layout'] ?? ''), true);
            $fields = is_array($layout) ? self::layoutFields($layout) : [];
            $values = self::recordValues($record, $fields)['values'] + $values;
        }
        foreach (is_array($template['values'] ?? null) ? $template['values'] : [] as $field => $value) {
            if (is_scalar($value) && ! isset($values[(string) $field])) {
                $values[(string) $field] = sanitize_text_field((string) $value);
            }
        }
        try {
            return FilledLabel::fromJson((string) json_encode([
                'format'   => $template['format'] ?? [],
                'layout'   => json_decode((string) ($template['layout'] ?? ''), true),
                'values'   => $values,
                'template' => (string) ($template['name'] ?? ''),
                'printer'  => is_array($template['printer'] ?? null) ? $template['printer'] : null,
            ]));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * D-1192: the label and printer chosen in the dialog — one select each, the chosen label first, the printers its roll allows.
     * Every label is filled here, so what prints is what the dialog showed; a small script keeps the printer list to the label.
     *
     * @param array<string, mixed> $template
     * @return array{html: string, hidden: string}|null
     */
    private static function chooser(LabelContent $label, array $template): ?array
    {
        $choices = is_array($template['choices'] ?? null) ? $template['choices'] : [];
        if ($choices === []) {
            return null;
        }
        $chosen   = (int) ($template['chosen'] ?? array_key_first($choices));
        $labels   = '';
        // ⚠️ *His wish 2026-11-06 «druck button pro positon»: a list draws one dialog per row, so the record's own words are read
        // when the label is printed, not when the dialog is drawn — no query per row (CD-7).*
        $record   = absint($template['record'] ?? 0);
        $hidden   = $record > 0 ? '<input type="hidden" name="record" value="' . $record . '">' : '';
        foreach ($choices as $id => $choice) {
            $filled = is_array($choice) ? self::filled($label, array_merge($choice, ['record' => 0])) : null;
            if ($filled === null) {
                continue;
            }
            $labels .= '<option value="' . (int) $id . '"' . selected((int) $id, $chosen, false) . '>' . esc_html(trim($filled->templateName . ' · ' . self::size($filled->format), ' ·')) . '</option>';
            $hidden .= '<input type="hidden" name="documents[' . (int) $id . ']" value="' . esc_attr($filled->toJson()) . '">';
            // D-1199: the texts a label may print in one of its fields, one per choice in the dialog — the module fills them.
            foreach (self::variantsOf($choice) as $key => $variant) {
                $hidden .= '<input type="hidden" name="variants[' . (int) $id . '][' . esc_attr($key) . ']" value="' . esc_attr($variant['text']) . '">';
            }
        }
        if ($labels === '') {
            return null;
        }

        $printers = '<option value="" data-allows="*">' . esc_html__('Ohne Drucker (wie bisher)', 'taxmod-label') . '</option>';
        $first    = true;
        foreach (is_array($template['printers'] ?? null) ? $template['printers'] : [] as $id => $printer) {
            $allows = array_map('intval', (array) ($printer['allows'] ?? []));
            $pick   = $first && in_array($chosen, $allows, true);
            $first  = $first && ! $pick;
            $printers .= '<option value="' . (int) $id . '" data-allows="' . esc_attr(implode(' ', $allows)) . '"' . ($pick ? ' selected' : '') . '>'
                . esc_html(trim((string) ($printer['name'] ?? '') . ' · ' . (string) ($printer['roll_name'] ?? ''), ' ·')) . '</option>';
            $hidden .= '<input type="hidden" name="printers[' . (int) $id . ']" value="' . esc_attr((string) json_encode($printer)) . '">';
        }

        $html = '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Etikett', 'taxmod-label') . '</span>'
            . '<select name="choice" data-taxmod-label-choice>' . $labels . '</select>'
            // D-1284: the module says where the preselected label comes from (a Bauform, a group), shown beneath the choice.
            . (trim((string) ($template['origin'] ?? '')) === '' ? '' : '<span class="description" style="display:block">' . esc_html(trim((string) $template['origin'])) . '</span>')
            . '</label>'
            . '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html__('Drucker', 'taxmod-label') . '</span>'
            . '<select name="printer" data-taxmod-label-printer>' . $printers . '</select></label>'
            . self::variantChooser($template)
            . self::chooserScript();

        return ['html' => $html, 'hidden' => $hidden];
    }

    /**
     * D-1199: `variants` = {field, label, chosen, options: {key: {name, text}}} — what a module offers to print in one field.
     *
     * @param mixed $template
     * @return array<string, array{name: string, text: string}>
     */
    private static function variantsOf(mixed $template): array
    {
        $options = is_array($template) && is_array($template['variants']['options'] ?? null) ? $template['variants']['options'] : [];
        $out     = [];
        foreach ($options as $key => $option) {
            if (is_array($option) && preg_match('/^[a-z0-9_]{0,32}$/', (string) $key) === 1) {
                $out[(string) $key] = ['name' => (string) ($option['name'] ?? $key), 'text' => (string) ($option['text'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * D-1199, his choice «Auswahl im Druckdialog»: one select for what the label prints in its variant field, preselected as the
     * module says; nothing where no label offers one.
     *
     * @param array<string, mixed> $template
     */
    private static function variantChooser(array $template): string
    {
        $options = self::variantsOf($template);
        $field   = (string) ($template['variants']['field'] ?? '');
        if ($options === [] || preg_match('/^[a-z0-9_]{1,32}$/', $field) !== 1) {
            return '';
        }
        $chosen = (string) ($template['variants']['chosen'] ?? array_key_first($options));
        $html   = '';
        foreach ($options as $key => $option) {
            $html .= '<option value="' . esc_attr($key) . '"' . selected($key, $chosen, false) . '>' . esc_html($option['name']) . '</option>';
        }

        return '<input type="hidden" name="variant_field" value="' . esc_attr($field) . '">'
            . '<label style="display:block;margin-bottom:.5em"><span style="display:block;font-weight:600">' . esc_html((string) ($template['variants']['label'] ?? $field)) . '</span>'
            . '<select name="variant">' . $html . '</select></label>';
    }

    /**
     * Once per page: «Print» sends the form in the background, closes the dialog and hands the answer to the page's message
     * place as the event `taxmod:message` ({text, ok}); a page without one (nobody cancels the event) shows the shared
     * `taxmod-toast`. Without the script the form posts and the page comes back as before.
     */
    private static function sendScript(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;

        return '<script>(function(){function toast(t,ok){document.querySelectorAll(".taxmod-toast").forEach(function(o){o.parentNode.removeChild(o);});'
            . 'var d=document.createElement("div");d.className="taxmod-toast "+(ok?"taxmod-toast-ok":"taxmod-toast-bad");d.setAttribute("role","status");d.textContent=t;document.body.appendChild(d);}'
            . 'function say(t,ok){var ev=new CustomEvent("taxmod:message",{detail:{text:t,ok:ok},cancelable:true});if(document.dispatchEvent(ev)){toast(t,ok);}}'
            . 'function watch(f,job,n){if(n>=200){return;}setTimeout(function(){var d=new FormData();d.append("action",' . wp_json_encode(self::STATUS_ACTION) . ');d.append("job",job);'
            . 'var nn=f.querySelector("input[name=' . self::NONCE . ']");if(nn){d.append(' . wp_json_encode(self::NONCE) . ',nn.value);}'
            . 'fetch(' . wp_json_encode(admin_url('admin-ajax.php')) . ',{method:"POST",body:d,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){'
            . 'if(!j||!j.success){return;}if(j.data.done){say(j.data.message,!!j.data.ok);return;}if(j.data.waiting&&j.data.waiting!==f.dataset.waiting){f.dataset.waiting=j.data.waiting;say(j.data.waiting,false);}watch(f,job,n+1);}).catch(function(){});},3000);}'
            . 'document.addEventListener("submit",function(e){var f=e.target,b=e.submitter;if(!f||!b||b.name!=="how"||b.value!=="service"){return;}'
            . 'var a=f.querySelector("input[name=action]");if(!a||a.value!==' . wp_json_encode(self::ACTION) . '||!window.fetch||!window.FormData){return;}'
            . 'e.preventDefault();var data=new FormData(f);data.append("how","service");data.append("taxmod_ajax","1");b.disabled=true;'
            . 'fetch(f.getAttribute("action"),{method:"POST",body:data,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){'
            . 'say((j&&j.data&&j.data.message)||' . wp_json_encode(__('The label could not be printed.', 'taxmod-label')) . ',!!(j&&j.success));'
            . 'if(j&&j.success){var sw=f.querySelector(".taxmod-dialog-switch");if(sw){sw.checked=false;}if(j.data.job){watch(f,j.data.job,0);}}})'
            . '.catch(function(){say(' . wp_json_encode(__('The label could not be printed.', 'taxmod-label')) . ',false);}).then(function(){b.disabled=false;});},true);})();</script>';
    }

    public static function previewShown(): bool
    {
        return (string) get_option(self::PREVIEW_OPTION, '1') !== '0';
    }

    /**
     * D-1207, his wish 2026-11-06: «etiketten vorschau im druck dialog, ausschaltbar über config» — the label as it would print,
     * drawn when the dialog opens and again when a choice in it changes. The dialog posts itself as «Open as PDF» with one copy
     * and shows the answer, so the preview is the print path itself (D-1161) and a list of rows renders nothing until one opens.
     */
    private static function previewBox(): string
    {
        if (! self::previewShown()) {
            return '';
        }

        return '<span class="taxmod-label-dialog-preview" data-taxmod-label-preview><span style="display:block;font-weight:600">' . esc_html__('Vorschau', 'taxmod-label') . '</span>'
            . '<iframe title="' . esc_attr__('Label preview', 'taxmod-label') . '" style="display:block;width:100%;max-width:320px;height:200px;border:1px solid #c3c4c7;background:#fff"></iframe>'
            . '<span class="description" data-taxmod-label-preview-note style="display:block"></span></span>'
            . self::previewScript();
    }

    /** Once per page: fetch the dialog's own PDF (one copy) into its preview frame, on opening and on each change. */
    private static function previewScript(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;

        return '<script>(function(){var timers=new WeakMap();function show(f){var box=f&&f.querySelector("[data-taxmod-label-preview]");if(!box||!window.fetch||!window.FormData){return;}'
            . 'var frame=box.querySelector("iframe"),note=box.querySelector("[data-taxmod-label-preview-note]"),data=new FormData(f);data.set("how","pdf");data.set("copies","1");data.set("preview","1");note.textContent=' . wp_json_encode(__('Wird gezeichnet …', 'taxmod-label')) . ';'
            . 'fetch(f.getAttribute("action"),{method:"POST",body:data,credentials:"same-origin"}).then(function(r){var pdf=(r.headers.get("Content-Type")||"").indexOf("application/pdf")===0;return r.ok&&pdf?r.blob():Promise.reject();})'
            . '.then(function(b){if(frame.dataset.url){URL.revokeObjectURL(frame.dataset.url);}frame.dataset.url=URL.createObjectURL(b);frame.src=frame.dataset.url+"#toolbar=0&navpanes=0&view=Fit";note.textContent="";})'
            . '.catch(function(){note.textContent=' . wp_json_encode(__('Die Vorschau lässt sich nicht zeichnen.', 'taxmod-label')) . ';});}'
            . 'function later(f){clearTimeout(timers.get(f));timers.set(f,setTimeout(function(){show(f);},250));}'
            . 'document.addEventListener("change",function(e){var t=e.target,f=t&&t.form;if(!f||!f.querySelector("[data-taxmod-label-preview]")){return;}'
            . 'if(t.classList.contains("taxmod-dialog-switch")){if(t.checked){later(f);}return;}if(t.name!=="copies"){later(f);}});})();</script>';
    }

    /** Once per page: a printer whose loaded roll does not allow the chosen label cannot be picked. */
    private static function chooserScript(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;

        return '<script>(function(){function sync(form,pick){var l=form.querySelector("[data-taxmod-label-choice]"),p=form.querySelector("[data-taxmod-label-printer]");if(!l||!p){return;}'
            . 'var keep=null;Array.prototype.forEach.call(p.options,function(o){var a=(o.getAttribute("data-allows")||"").split(" ");o.disabled=a.indexOf("*")<0&&a.indexOf(l.value)<0;if(!o.disabled&&o.value!==""&&keep===null){keep=o;}});'
            . 'if(pick||(p.selectedOptions.length&&p.selectedOptions[0].disabled)){p.value=keep?keep.value:"";}}'
            . 'document.addEventListener("change",function(e){if(e.target.matches&&e.target.matches("[data-taxmod-label-choice]")){sync(e.target.form,true);}});'
            . 'document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("[data-taxmod-label-choice]").forEach(function(l){sync(l.form,false);});});})();</script>';
    }

    /**
     * D-1160: the record's own words for these fields; nothing where the modeller was booted without its screen.
     *
     * @param list<string> $fields
     * @return array{values: array<string, string>, unknown: list<string>, node: int}
     */
    public static function recordValues(int $record, array $fields): array
    {
        return (new LabelService())->recordValues($record, $fields);
    }

    /** 1 … {@see self::MAX_COPIES}: what a module asked for or a person typed, held to what a printer run can mean. */
    public static function copies(int $wanted): int
    {
        return max(1, min(self::MAX_COPIES, $wanted));
    }

    /**
     * The fields a layout reads; «Widerstandswert|Kapazität» names each of them (D-1217).
     *
     * @param array<mixed> $layout
     * @return list<string>
     */
    private static function layoutFields(array $layout): array
    {
        $fields = [];
        foreach ($layout as $element) {
            $field = is_array($element) ? (string) ($element['field'] ?? $element['feld'] ?? '') : '';
            foreach (explode('|', $field) as $one) {
                if (trim($one) !== '') {
                    $fields[] = trim($one);
                }
            }
        }

        return array_values(array_unique($fields));
    }
}
