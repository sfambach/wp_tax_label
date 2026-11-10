<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use TaxmodLabel\Core\FilledLabel;
use TaxmodLabel\Core\LabelContent;
use TaxmodLabel\Core\LabelDocument;
use TaxmodLabel\Core\LabelFormat;
use TaxmodLabel\Core\LabelProblems;

/**
 * D-1294: a print job stored before the jobs carried their PDF («Nochmal drucken» of an old job) is drawn here, as the modeller
 * drew it then — from its document, or from its fixed format with code and two lines.
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1294
 */
final class PrintRender
{
    public const FILTER = 'taxmod_print_render';

    public static function boot(): void
    {
        add_filter(self::FILTER, static fn (mixed $answer, mixed $row = null): mixed => is_array($row) ? (self::render($row) ?? $answer) : $answer, 10, 2);
    }

    /**
     * @param array<string, mixed> $row the stored job
     * @return array{pdf: string, format: string, roll: float, queue?: string, options?: array<string, string>, retry?: bool}|null
     */
    public static function render(array $row): ?array
    {
        $copies = max(1, (int) ($row['copies'] ?? 1));
        try {
            if (is_string($row['document'] ?? null) && $row['document'] !== '') {
                $filled  = FilledLabel::fromJson($row['document']);
                $printer = $filled->printer;

                return [
                    'pdf'    => $filled->render($copies, false, null, LabelService::pictures($filled->layout->media())),
                    'format' => $filled->format->key,
                    'roll'   => $filled->format->rollMm,
                ] + ($printer === null ? [] : ['queue' => $printer->queue, 'options' => $printer->optionsFor($filled->format), 'retry' => $printer->retries]);
            }
            $format = LabelFormat::shipped()[(string) ($row['format'] ?? '')] ?? null;
            if ($format === null) {
                return null;
            }
            $label = new LabelContent((string) ($row['code'] ?? ''), (string) ($row['title'] ?? ''), (string) ($row['subtitle'] ?? ''));

            return ['pdf' => LabelDocument::render($format, array_fill(0, $copies, $label)), 'format' => $format->key, 'roll' => $format->rollMm];
        } catch (LabelProblems | \InvalidArgumentException) {
            return null;
        }
    }
}
