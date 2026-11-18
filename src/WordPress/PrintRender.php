<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use TaxmodLabel\Core\FilledLabel;
use TaxmodLabel\Core\LabelContent;
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
        $copies = LabelButton::copies((int) ($row['copies'] ?? 1));
        $label  = new LabelContent((string) ($row['code'] ?? ''), (string) ($row['title'] ?? ''), (string) ($row['subtitle'] ?? ''));
        try {
            // D-1439: drawn through the one render path, as every other label.
            if (is_string($row['document'] ?? null) && $row['document'] !== '') {
                $filled  = FilledLabel::fromJson($row['document']);
                $printer = $filled->printer;

                return [
                    'pdf'    => LabelRender::run([['label' => $label, 'copies' => $copies, 'filled' => $filled]], LabelRender::TO_PDF)['pdf'] ?? '',
                    'format' => $filled->format->key,
                    'roll'   => $filled->format->rollMm,
                ] + ($printer === null ? [] : ['queue' => $printer->queue, 'options' => $printer->optionsFor($filled->format), 'retry' => $printer->retries, 'pause' => $printer->pauseSeconds]);
            }
            $format = LabelFormat::shipped()[(string) ($row['format'] ?? '')] ?? null;
            if ($format === null) {
                return null;
            }

            return ['pdf' => LabelRender::run([['label' => $label, 'copies' => $copies, 'format' => $format]], LabelRender::TO_PDF)['pdf'] ?? '', 'format' => $format->key, 'roll' => $format->rollMm];
        } catch (LabelProblems | \RuntimeException | \InvalidArgumentException) {
            return null;
        }
    }
}
