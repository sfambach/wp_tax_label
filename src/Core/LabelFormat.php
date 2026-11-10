<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * The size of one label on a roll, with the label printed across the roll (D-1032).
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1032
 */
final class LabelFormat
{
    public function __construct(
        public readonly string $key,
        public readonly float $widthMm,
        public readonly float $heightMm,
        public readonly float $rollMm,
        public readonly ?bool $turned = null,
        public readonly float $offsetMm = 0.0,
        public readonly float $lineMm = 0.0,
    ) {
    }

    /**
     * The formats shipped today: 62 mm roll (QL-810) with a 100 mm label, and the 102 mm roll (QL-1100), 62 × 50 mm for the label holders on the places, and 62 × 30 mm for material.
     *
     * @return array<string, self>
     */
    public static function shipped(): array
    {
        $formats = [
            new self('roll62', 100.0, 62.0, 62.0),
            new self('roll102', 102.0, 62.0, 102.0),
            new self('holder62', 62.0, 50.0, 62.0),
            new self('material62', 62.0, 30.0, 62.0),
        ];
        return array_combine(array_map(static fn (self $format): string => $format->key, $formats), $formats);
    }

    /** D-1228: the label as a person sees it — without the move a printer that starts part-way along its head needs. */
    public function asSeen(): self
    {
        return new self($this->key, $this->widthMm, $this->heightMm, $this->rollMm, $this->turned);
    }

    /**
     * The Brother driver feeds a page with its height across the roll, so a label as wide as the roll is turned a quarter —
     * unless its printer says otherwise (D-1190: the Phomemo takes the label as it is).
     */
    public function turnedOnRoll(): bool
    {
        if ($this->turned !== null) {
            return $this->turned;
        }
        return abs($this->widthMm - $this->rollMm) < 0.01 && abs($this->heightMm - $this->rollMm) >= 0.01;
    }

    public static function byKey(string $key): self
    {
        $formats = self::shipped();
        if (!isset($formats[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown label format "%s".', $key));
        }
        return $formats[$key];
    }
}
