<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

use TaxmodLabel\Core\Drawing\Resolution;

/**
 * A printer as the model keeps it (D-1191): its CUPS queue, the roll it holds, whether it turns labels as wide as that roll,
 * the CUPS options it always gets and those it gets for a turned label, the CUPS name of each label size, and — for a printer
 * that starts its lines part-way along the head — that offset and the length of its line. A label carries none of this; it is
 * printed on the printer whose roll it fits.
 *
 * ⚠️ *The queue and the options reach `lp` on the home PC, so they are held to plain names here and again by the print
 * service; nothing that looks like a flag or a path gets through.*
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1191
 */
final class LabelPrinter
{
    private const NAME   = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,126}$/';
    private const OPTION = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,62}$/';

    /** The key of the size entry that stands for every size: «*=w{B}h{H}». */
    private const ANY_SIZE = '*';

    /**
     * @param array<string, string> $options     CUPS option ⇒ value for every label; '' for an option without value
     * @param array<string, string> $turnOptions the same, added for a label turned on its page
     * @param array<string, string> $sizes       «62x30» (label width × height) ⇒ the PageSize the queue knows; «*» with {B}/{H}
     */
    public function __construct(
        public readonly string $queue,
        public readonly array $options = [],
        public readonly bool $turnsLabel = false,
        public readonly string $name = '',
        public readonly float $offsetMm = 0.0,
        public readonly float $lineMm = 0.0,
        public readonly float $rollMm = 0.0,
        public readonly array $turnOptions = [],
        public readonly array $sizes = [],
        public readonly bool $retries = false,
        /** Assumed field «Auflösung» (docs/konzept.md §10); 0 where the printer does not say. */
        public readonly float $dpi = 0.0,
    ) {
        if (preg_match(self::NAME, $queue) !== 1) {
            throw new \InvalidArgumentException(sprintf('«%s» is not a CUPS queue name.', $queue));
        }
        foreach ([$options, $turnOptions] as $set) {
            foreach ($set as $option => $value) {
                self::plainOption((string) $option, $value);
            }
        }
        foreach ($sizes as $size => $pageSize) {
            if (preg_match('/^(\*|\d+(\.\d+)?x\d+(\.\d+)?)$/', (string) $size) !== 1) {
                throw new \InvalidArgumentException(sprintf('«%s» is not a label size like 62x30.', $size));
            }
            self::plainOption('PageSize', str_replace(['{B}', '{H}'], ['1', '1'], $pageSize));
        }
    }

    /**
     * «PageSize=w40h30 orientation-requested=4» — as a person writes them, one per line or space.
     *
     * @return array<string, string>
     */
    public static function parseOptions(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            [$option, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $out[$option] = $value;
        }

        return $out;
    }

    /**
     * The CUPS options for one label on this printer: its own, those for a turned label, and the PageSize of the label's size.
     *
     * @return array<string, string>
     */
    public function optionsFor(LabelFormat $format): array
    {
        $options = $this->options + ($format->turnedOnRoll() ? $this->turnOptions : []);
        $size    = self::number($format->widthMm) . 'x' . self::number($format->heightMm);
        $page    = $this->sizes[$size] ?? (isset($this->sizes[self::ANY_SIZE]) ? str_replace(['{B}', '{H}'], [self::number($format->widthMm), self::number($format->heightMm)], $this->sizes[self::ANY_SIZE]) : null);

        return $page === null ? $options : ['PageSize' => $page] + $options;
    }

    /** The dots the codes are drawn to: the printer's own, else the worst printer in the house. */
    public function resolution(): Resolution
    {
        return new Resolution($this->dpi > 0.0 ? $this->dpi : Resolution::DEFAULT_DPI);
    }

    /** @return array{queue: string, options: array<string, string>, turn: bool, name: string, offset: float, line: float, roll: float, turn_options: array<string, string>, sizes: array<string, string>, retry: bool} */
    public function toArray(): array
    {
        return ['queue' => $this->queue, 'options' => $this->options, 'turn' => $this->turnsLabel, 'name' => $this->name, 'offset' => $this->offsetMm,
            'line' => $this->lineMm, 'roll' => $this->rollMm, 'turn_options' => $this->turnOptions, 'sizes' => $this->sizes, 'retry' => $this->retries, 'dpi' => $this->dpi];
    }

    /**
     * Options and sizes may come as maps or as the text of the printer's fields.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $map = static function (mixed $given): array {
            $given = is_string($given) ? self::parseOptions($given) : $given;
            $out   = [];
            foreach (is_array($given) ? $given : [] as $key => $value) {
                if (is_scalar($value)) {
                    $out[(string) $key] = (string) $value;
                }
            }
            return $out;
        };
        $mm   = static fn (string $key): float => is_numeric(str_replace(',', '.', (string) ($raw[$key] ?? ''))) ? max(0.0, (float) str_replace(',', '.', (string) $raw[$key])) : 0.0;
        $yes  = static fn (mixed $given): bool => $given === true || $given === 1 || in_array(strtolower((string) $given), ['1', 'true', 'ja', 'yes'], true);
        $turn = $raw['turn'] ?? false;

        return new self(
            trim((string) ($raw['queue'] ?? '')),
            $map($raw['options'] ?? []),
            $yes($turn),
            trim((string) ($raw['name'] ?? '')),
            $mm('offset'),
            $mm('line'),
            $mm('roll'),
            $map($raw['turn_options'] ?? []),
            $map($raw['sizes'] ?? []),
            // D-1234: whether CUPS may try a failed job again by itself; off unless the printer says so.
            $yes($raw['retry'] ?? false),
            $mm('dpi') >= 50.0 && $mm('dpi') <= 2400.0 ? $mm('dpi') : 0.0,
        );
    }

    private static function plainOption(string $option, string $value): void
    {
        if (preg_match(self::OPTION, $option) !== 1 || ($value !== '' && preg_match(self::OPTION, $value) !== 1)) {
            throw new \InvalidArgumentException(sprintf('«%s=%s» is not a CUPS option.', $option, $value));
        }
    }

    private static function number(float $mm): string
    {
        return rtrim(rtrim(number_format($mm, 2, '.', ''), '0'), '.');
    }
}
