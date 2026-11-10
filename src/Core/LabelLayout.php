<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * What goes where on a label, written as JSON so a person can change it without a release (D-1153).
 *
 * Two shapes are read: the list of elements stored today, and the editor's document — the label's settings on top, the
 * elements below (Stefan 2026-11-10). German keys of the first sketch (`typ`, `feld`, `b` …) are read too.
 *
 * ```mermaid
 * flowchart LR
 *   J["JSON: list, or {label, elements}"] --> P["LabelLayout::parse"]
 *   P --> L["LabelLayout + LabelSettings"]
 *   P --> X["problems, each with its element"]
 * ```
 *
 * @see docs/konzept.md §3, §4
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1153
 */
final class LabelLayout
{
    private const ALIASES = ['typ' => 'type', 'feld' => 'field', 'b' => 'w', 'fett' => 'bold', 'zeilen' => 'lines', 'unten' => 'bottom', 'beispiel' => 'example', 'kurz' => 'short', 'mitte' => 'center', 'rechts' => 'right', 'linie' => 'line', 'rahmen' => 'rect', 'bild' => 'image', 'anpassen' => 'fit'];

    /** @param list<LabelElement> $elements */
    public function __construct(public readonly array $elements, public readonly ?LabelSettings $settings = null)
    {
    }

    /**
     * Reads a layout and says everything wrong with its shape; the layout is null only where nothing could be read.
     *
     * @return array{0: ?self, 1: list<Problem>}
     */
    public static function parse(string $json): array
    {
        try {
            $raw = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [null, [new Problem(Problem::JSON, 0, ['message' => $e->getMessage()])]];
        }

        $settings = null;
        $problems = [];
        if (is_array($raw) && ! array_is_list($raw)) {
            if (is_array($raw['label'] ?? null)) {
                $settings = LabelSettings::fromArray($raw['label']);
                $problems = $settings->problems();
            }
            $raw = $raw['elements'] ?? null;
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            return [null, [new Problem(Problem::SHAPE)]];
        }

        $elements = [];
        foreach ($raw as $i => $entry) {
            [$element, $found] = self::element($entry, $i + 1);
            $problems          = [...$problems, ...$found];
            if ($element !== null) {
                $elements[] = $element;
            }
        }

        return [new self($elements, $settings), $problems];
    }

    /** Reads a layout that must be whole; the first problem is the exception's message. */
    public static function fromJson(string $json): self
    {
        [$layout, $problems] = self::parse($json);
        if ($layout === null || Problem::hasError($problems)) {
            throw new LabelProblems($problems === [] ? [new Problem(Problem::SHAPE)] : $problems);
        }

        return $layout;
    }

    public function withSettings(?LabelSettings $settings): self
    {
        return new self($this->elements, $settings);
    }

    /** The list of elements as it is stored in the label record. */
    public function toJson(): string
    {
        $lines = array_map(static fn (LabelElement $e): string => json_encode($e->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->elements);

        return $lines === [] ? '[]' : "[\n " . implode(",\n ", $lines) . "\n]";
    }

    /** The editor's document: settings on top, elements below. */
    public function toDocument(): string
    {
        return json_encode(
            array_filter(['label' => $this->settings?->toArray(), 'elements' => array_map(static fn (LabelElement $e): array => $e->toArray(), $this->elements)], static fn (mixed $v): bool => $v !== null),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
    }

    /** The boxes must stay on the label; a box outside it is a typo that would print nothing. */
    public function fitsOn(LabelFormat $format): bool
    {
        return $this->outside($format->widthMm, $format->heightMm) === [];
    }

    /** @return list<Problem> one for every box that reaches past a label of this size */
    public function outside(float $widthMm, float $heightMm): array
    {
        $out = [];
        foreach ($this->elements as $i => $e) {
            if ($e->xMm + $e->widthMm > $widthMm + 0.01 || $e->yMm + $e->heightMm > $heightMm + 0.01) {
                $out[] = new Problem(Problem::OUTSIDE, $i + 1, ['w' => $widthMm, 'h' => $heightMm]);
            }
        }

        return $out;
    }

    /**
     * D-1154: the example values the layout itself carries, the first one written for a field winning.
     *
     * @return array<string, string> field ⇒ example
     */
    public function examples(): array
    {
        $out = [];
        foreach ($this->elements as $e) {
            $first = $e->alternatives()[0] ?? '';
            if ($e->example !== '' && $first !== '' && ! isset($out[$first])) {
                $out[$first] = $e->example;
            }
        }

        return $out;
    }

    /** @return list<string> the fields the layout reads, in order, each once — every alternative of «a|b» on its own */
    public function fields(): array
    {
        return array_values(array_unique(array_merge([], ...array_map(static fn (LabelElement $e): array => $e->alternatives(), $this->elements))));
    }

    /** @return list<int> the media library ids of the pictures */
    public function media(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (LabelElement $e): int => $e->media, $this->elements))));
    }

    /**
     * @return array{0: ?LabelElement, 1: list<Problem>}
     */
    private static function element(mixed $entry, int $number): array
    {
        if (! is_array($entry) || array_is_list($entry)) {
            return [null, [new Problem(Problem::SHAPE, $number)]];
        }
        $e = [];
        foreach ($entry as $key => $value) {
            $e[self::ALIASES[$key] ?? $key] = $value;
        }
        $type = self::ALIASES[(string) ($e['type'] ?? LabelElement::TEXT)] ?? (string) ($e['type'] ?? LabelElement::TEXT);
        if (! in_array($type, LabelElement::TYPES, true)) {
            return [null, [new Problem(Problem::UNKNOWN_TYPE, $number, ['type' => $type])]];
        }

        $number_ = static fn (string $key, float $default = 0.0): float => is_numeric($e[$key] ?? null) ? (float) $e[$key] : $default;
        $text    = static fn (string $key): string => is_scalar($e[$key] ?? null) ? trim((string) $e[$key]) : '';
        $flag    = static fn (string $key, bool $default = false): bool => is_bool($e[$key] ?? null) ? $e[$key] : $default;

        $width  = $number_('w');
        $height = $number_('h', in_array($type, LabelElement::MATRIX_CODES, true) ? $width : 0.0);
        $fit    = $text('fit');
        $media  = (int) preg_replace('/^media:/', '', $text('media'));

        $element = new LabelElement(
            $type,
            $number_('x'),
            $number_('y'),
            $width,
            $height,
            field: $text('field'),
            text: $text('text'),
            maxPt: $number_('pt', 12.0),
            bold: $flag('bold'),
            lines: max(1, (int) $number_('lines', 1.0)),
            atBottom: $flag('bottom'),
            example: $text('example'),
            short: $flag('short'),
            centred: $flag('center'),
            flushRight: $flag('right'),
            fit: in_array($fit, LabelElement::FITS, true) ? $fit : LabelElement::FIT_SHRINK,
            strokeMm: $number_('stroke', 0.3),
            human: $flag('human', true),
            media: max(0, $media),
            anyShape: $text('shape') === 'any',
        );

        $problems = [];
        if ($width <= 0.0 || $height <= 0.0 || $element->xMm < 0.0 || $element->yMm < 0.0 || $element->maxPt <= 0.0 || $element->strokeMm <= 0.0) {
            $problems[] = new Problem(Problem::NEEDS_BOX, $number);
        }
        if ($element->readsValue() && $element->text === '' && $element->alternatives() === []) {
            $problems[] = new Problem(Problem::NEEDS_FIELD, $number);
        }
        if ($type === LabelElement::IMAGE && $element->media === 0) {
            $problems[] = new Problem(Problem::NEEDS_MEDIA, $number);
        }

        return [$problems === [] ? $element : null, $problems];
    }
}
