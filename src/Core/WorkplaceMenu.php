<?php declare(strict_types=1);

namespace TaxmodLabel\Core;

/**
 * D-1502 Stufe 3: what the switch in the admin bar offers — the person's workplace in its title, every workplace and «kein
 * Arbeitsplatz» to choose, and whether to remind them that none is chosen.
 *
 * @see wp-taxonomy-modeler docs/pakete/arbeitsplatz/konzept.md
 */
final class WorkplaceMenu
{
    /** @param array<int, string> $places workplace id ⇒ name, in the order shown */
    public function __construct(private readonly array $places, private readonly ?Workplace $current)
    {
    }

    /** The current workplace's name; '' without one. */
    public function currentName(): string
    {
        return $this->current === null ? '' : ($this->places[$this->current->id] ?? $this->current->name);
    }

    /** @return list<array{id: int, name: string, current: bool}> every workplace, then 0 for «kein Arbeitsplatz» (name '') */
    public function choices(): array
    {
        $now = $this->current?->id ?? 0;
        $out = [];
        foreach ($this->places as $id => $name) {
            $out[] = ['id' => $id, 'name' => $name, 'current' => $id === $now];
        }
        $out[] = ['id' => 0, 'name' => '', 'current' => $now === 0];

        return $out;
    }

    /** Whether `$id` may be chosen: a workplace of the list, or 0 for none. */
    public function allows(int $id): bool
    {
        return $id === 0 || isset($this->places[$id]);
    }

    /** Remind the person only where there are workplaces to choose and none is chosen. */
    public function remindsToChoose(): bool
    {
        return $this->places !== [] && $this->current === null;
    }
}
