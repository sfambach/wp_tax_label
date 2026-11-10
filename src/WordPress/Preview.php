<?php declare(strict_types=1);

namespace TaxmodLabel\WordPress;

use TaxmodLabel\Core\Drawing\Resolution;
use TaxmodLabel\Core\LabelDocument;
use TaxmodLabel\Core\LabelFormat;
use TaxmodLabel\Core\LabelLayout;
use TaxmodLabel\Core\LabelProblems;
use TaxmodLabel\Core\Problem;

/**
 * The editor's drawing: one layout through the one renderer, as SVG to look at or PDF to print — the editor never draws itself.
 *
 * @see docs/konzept.md §5
 */
final class Preview
{
    /**
     * @param array<string, mixed>|null $values null: the examples of the layout
     * @return array{svg?: string, pdf?: string, width: float, height: float, dpi: float, problems: list<array{element: int, error: bool, text: string}>, layout: string}
     */
    public static function draw(string $json, float $widthMm, float $heightMm, ?array $values, float $dpi, bool $asPdf): array
    {
        $resolution = new Resolution($dpi > 0 ? $dpi : Resolution::DEFAULT_DPI);
        [$layout, $problems] = LabelLayout::parse($json);
        $answer = ['width' => $widthMm, 'height' => $heightMm, 'dpi' => $resolution->dpi, 'layout' => ''];
        if ($layout === null) {
            return $answer + ['problems' => ProblemWords::list($problems)];
        }
        if ($layout->settings !== null) {
            $widthMm  = $layout->settings->widthMm;
            $heightMm = $layout->settings->heightMm;
        }
        $answer = ['width' => $widthMm, 'height' => $heightMm, 'layout' => $layout->toDocument()] + $answer;
        if ($widthMm <= 0 || $heightMm <= 0) {
            return $answer + ['problems' => ProblemWords::list([...$problems, new Problem(Problem::BAD_SIZE)])];
        }
        $filled   = array_map('strval', array_filter($values ?? $layout->examples() + ['code' => 'M-12345'], 'is_scalar'));
        $pictures = LabelService::pictures($layout->media());
        [$svg, $drawn] = LabelDocument::svg($widthMm, $heightMm, $layout, $filled, $resolution, $pictures);
        $problems = [...$problems, ...$drawn];
        if ($asPdf && ! Problem::hasError($problems)) {
            try {
                $format        = new LabelFormat('preview', $widthMm, $heightMm, $layout->settings?->rollMm ?: 0.0);
                $answer['pdf'] = base64_encode(LabelDocument::renderLayout($format, $layout, [$filled], $resolution, $pictures));
            } catch (LabelProblems $e) {
                $problems = [...$problems, ...$e->problems];
            }
        }

        return $answer + ['svg' => $svg, 'problems' => ProblemWords::list($problems)];
    }
}
