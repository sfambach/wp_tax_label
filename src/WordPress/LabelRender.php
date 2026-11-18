<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Printing\PrintService;
use TaxmodLabel\Core\FilledLabel;
use TaxmodLabel\Core\LabelContent;
use TaxmodLabel\Core\LabelDocument;
use TaxmodLabel\Core\LabelFormat;
use TaxmodLabel\Core\LabelProblems;

/**
 * D-1439: the one way a label is drawn — a list of labels, each with its copies; a single print is a list of one. Every host
 * (the dialog, the abilities, the stock's bulk print, the test print) asks here, none draws a label itself.
 *
 * ```mermaid
 * flowchart LR
 *   H["Host: list of labels"] --> R["LabelRender::run"]
 *   R -->|service| J["one job per label, copies as pages → ModelDoor::queuePrint"]
 *   R -->|pdf| P["one PDF, one page size"]
 * ```
 *
 * Asked from another plugin through {@see self::FILTER}:
 * `apply_filters('taxmod_label_render', null, list<array{code, title, subtitle?, copies?, template?, format?}>, 'service'|'pdf')`
 * answers `array{pdf?: string, jobs: list<int>, labels: int}` or a `WP_Error`; no answer (null) means the labels module is missing.
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1439, D-1294, D-1162
 */
final class LabelRender
{
    public const FILTER = 'taxmod_label_render';

    /** The fixed formats for a host's chooser: key ⇒ «name · size» (D-1439: they live here only). */
    public const FORMATS_FILTER = 'taxmod_label_formats';

    public const TO_SERVICE = 'service';

    public const TO_PDF = 'pdf';

    public static function boot(): void
    {
        add_filter(self::FILTER, static function (mixed $answer, mixed $items = [], mixed $to = self::TO_PDF): mixed {
            if (! is_array($items)) {
                return $answer;
            }
            try {
                return self::run(self::fromHost(array_values($items)), (string) $to);
            } catch (LabelProblems $e) {
                return new \WP_Error('taxmod_label_problems', implode(' ', array_map(static fn (array $p): string => $p['text'], ProblemWords::list($e->problems))), ['status' => 400]);
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                return new \WP_Error('taxmod_label_render', $e->getMessage(), ['status' => 400]);
            }
        }, 10, 3);
        add_filter(self::FORMATS_FILTER, static fn (mixed $answer): array => (is_array($answer) ? $answer : []) + self::formats());
    }

    /** @return array<string, string> */
    public static function formats(): array
    {
        $out = [];
        foreach (LabelFormat::shipped() as $format) {
            $out[$format->key] = self::formatName($format) . ' · ' . LabelButton::size($format);
        }

        return $out;
    }

    public static function formatName(LabelFormat $format): string
    {
        return match ($format->key) {
            'roll62'     => __('Platz- und Kistenetikett, Rolle 62 mm (QL-810)', 'taxmod-label'),
            'roll102'    => __('Platz- und Kistenetikett, Rolle 102 mm (QL-1100)', 'taxmod-label'),
            'holder62'   => __('Platzetikett für die Etikettenhalter, Rolle 62 mm', 'taxmod-label'),
            'material62' => __('Kleines Etikett für Material, Plätze und Schubladen, Rolle 62 mm', 'taxmod-label'),
            default      => $format->key,
        };
    }

    /**
     * The labels drawn: queued for the print service (one job per label, its copies as pages, D-1162), or one PDF.
     *
     * @param list<array{label: LabelContent, copies: int, filled?: FilledLabel, format?: LabelFormat}> $labels
     * @return array{pdf?: string, jobs: list<int>, labels: int}
     * @throws LabelProblems a label that cannot be printed as it stands
     * @throws \RuntimeException nothing to draw, or labels of different sizes for one PDF
     */
    public static function run(array $labels, string $to, bool $asSeen = false): array
    {
        if ($labels === []) {
            throw new \RuntimeException(__('Kein Etikett zum Drucken.', 'taxmod-label'));
        }
        $count = array_sum(array_map(static fn (array $one): int => $one['copies'], $labels));

        if ($to === self::TO_SERVICE) {
            if (! class_exists(PrintService::class) || ! PrintService::isSetUp()) {
                throw new \RuntimeException(__('The print service is not set up.', 'taxmod-label'));
            }
            $jobs = [];
            foreach ($labels as $one) {
                $jobs[] = self::queueOne($one);
            }

            return ['jobs' => $jobs, 'labels' => $count];
        }

        // One PDF has one page size: every label shares the format and layout of the first.
        $first = $labels[0];
        $kind  = self::kindOf($first);
        foreach ($labels as $one) {
            if (self::kindOf($one) !== $kind) {
                throw new \RuntimeException(__('Die Etiketten haben verschiedene Vorlagen; ein PDF hat nur eine Größe. Bitte getrennt drucken oder den Druckdienst nehmen.', 'taxmod-label'));
            }
        }
        if (isset($first['filled'])) {
            $filled   = $first['filled'];
            $format   = $asSeen ? $filled->format->asSeen() : $filled->format;
            $records  = array_merge(...array_map(static fn (array $one): array => array_fill(0, $one['copies'], $one['filled']->values), $labels));
            $pdf      = LabelDocument::renderLayout($format, $filled->layout, $records, $filled->printer?->resolution(), LabelService::pictures($filled->layout->media()));
        } else {
            $pdf = LabelDocument::render($first['format'], array_merge(...array_map(static fn (array $one): array => array_fill(0, $one['copies'], $one['label']), $labels)));
        }

        return ['pdf' => $pdf, 'jobs' => [], 'labels' => $count];
    }

    /** @param array{label: LabelContent, copies: int, filled?: FilledLabel, format?: LabelFormat} $one */
    private static function queueOne(array $one): int
    {
        $label = $one['label'];
        if (isset($one['filled'])) {
            $filled  = $one['filled'];
            $pdf     = $filled->render($one['copies'], false, null, LabelService::pictures($filled->layout->media()));
            $format  = $filled->format;
            $printer = $filled->printer;
            $name    = $filled->templateName;
        } else {
            $format  = $one['format'];
            $pdf     = LabelDocument::render($format, array_fill(0, $one['copies'], $label));
            $printer = null;
            $name    = '';
        }
        $delivery = ['roll' => $format->rollMm] + ($printer === null ? [] : ['queue' => $printer->queue, 'options' => $printer->optionsFor($format), 'retry' => $printer->retries ? 1 : 0]);

        return (new ModelDoor())->queuePrint($pdf, $name !== '' ? $name : $format->key, $label->code, $label->title, $one['copies'], $delivery);
    }

    /** @param array{label: LabelContent, copies: int, filled?: FilledLabel, format?: LabelFormat} $one */
    private static function kindOf(array $one): string
    {
        return isset($one['filled']) ? $one['filled']->format->key . '|' . $one['filled']->layout->toJson() : 'fixed|' . $one['format']->key;
    }

    /**
     * A host's list, checked: a code like M-123, a title, at most {@see LabelButton::MAX_COPIES} copies, and either a template
     * (filled as the dialog fills it) or a fixed format.
     *
     * @param list<mixed> $items
     * @return list<array{label: LabelContent, copies: int, filled?: FilledLabel, format?: LabelFormat}>
     */
    public static function fromHost(array $items): array
    {
        $labels  = [];
        $formats = LabelFormat::shipped();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = LabelPrint::labelFrom(['code' => (string) ($item['code'] ?? ''), 'title' => (string) ($item['title'] ?? ''), 'subtitle' => (string) ($item['subtitle'] ?? '')]);
            if ($label === null) {
                throw new \RuntimeException(__('A label needs a known format, a code like O-123 and a title.', 'taxmod-label'));
            }
            $copies = LabelButton::copies((int) ($item['copies'] ?? 1));
            if (is_array($item['template'] ?? null) && $item['template'] !== []) {
                $filled = LabelButton::filled($label, LabelButton::expanded($item['template']));
                if ($filled === null) {
                    throw new \RuntimeException(__('Das Etikett lässt sich nicht zeichnen; bitte sein Layout prüfen.', 'taxmod-label'));
                }
                $labels[] = ['label' => $label, 'copies' => $copies, 'filled' => $filled];
                continue;
            }
            $format = $formats[(string) ($item['format'] ?? '')] ?? null;
            if ($format === null) {
                throw new \RuntimeException(__('A label needs a known format, a code like O-123 and a title.', 'taxmod-label'));
            }
            $labels[] = ['label' => $label, 'copies' => $copies, 'format' => $format];
        }

        return $labels;
    }
}
