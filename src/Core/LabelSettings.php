<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * The label's own settings as the code editor shows them on top of the elements: size, roll and copies.
 *
 * ⚠️ *Stefan 2026-11-10, card «Felder, JSON zeigt sie»: they stay fields of the label record; the editor shows them here and
 * writes them back on saving, so each lives in one place.*
 *
 * @see docs/konzept.md §3
 */
final class LabelSettings
{
    public function __construct(
        public readonly float $widthMm,
        public readonly float $heightMm,
        public readonly float $rollMm = 0.0,
        public readonly int $copies = 1,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $number = static fn (string $key): float => is_numeric($raw[$key] ?? null) ? (float) $raw[$key] : 0.0;

        return new self($number('w'), $number('h'), $number('roll'), max(1, (int) $number('copies')));
    }

    /** @return list<Problem> */
    public function problems(): array
    {
        return $this->widthMm > 0.0 && $this->heightMm > 0.0 && $this->widthMm <= 500.0 && $this->heightMm <= 500.0 ? [] : [new Problem(Problem::BAD_SIZE)];
    }

    /** @return array{w: float, h: float, roll?: float, copies: int} */
    public function toArray(): array
    {
        return array_filter(['w' => $this->widthMm, 'h' => $this->heightMm, 'roll' => $this->rollMm > 0.0 ? $this->rollMm : null, 'copies' => $this->copies], static fn (mixed $v): bool => $v !== null);
    }
}
