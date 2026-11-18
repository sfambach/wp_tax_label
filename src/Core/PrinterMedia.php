<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * D-1441: which print media each printer can take; the other direction (which printers can take a medium) is derived, not stored.
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1441
 */
final class PrinterMedia
{
    /** @param array<int, list<int>> $media printer id → medium ids */
    private function __construct(private readonly array $media)
    {
    }

    public static function from(Table $printers, int $mediaField): self
    {
        $media = [];
        foreach ($printers->ids() as $printer) {
            $media[$printer] = array_values(array_unique(array_filter(array_map('intval', $printers->all($printer, $mediaField)), static fn (int $id): bool => $id > 0)));
        }

        return new self($media);
    }

    /** @return list<int> */
    public function of(int $printer): array
    {
        return $this->media[$printer] ?? [];
    }

    public function allows(int $printer, int $medium): bool
    {
        return in_array($medium, $this->of($printer), true);
    }

    /** @return list<int> the printers that can take this medium */
    public function printersFor(int $medium): array
    {
        return array_keys(array_filter($this->media, static fn (array $ids): bool => in_array($medium, $ids, true)));
    }
}
