<?php declare(strict_types=1);

namespace TaxmodLabel\Tests\Support;

/**
 * The modeller's rule book (`Taxmod\Core\Rules\RuleBook`, D-1541) for the core tests, which load no modeller: by position, then
 * id, the first rule whose every set condition is in its list; a rule that sets none holds nothing (D-1563 (3)). The module's code never decides this itself — it asks
 * `ModelDoor::firstRule`, and the modeller's `rule-book-check` holds the door to the real book.
 */
final class RuleBookStandIn
{
    /**
     * @param list<array{id: int, position: int, conditions: array<int, int>}> $book
     * @param array<int, list<int>> $context
     */
    public static function first(array $book, array $context, ?\Closure $usable = null): ?int
    {
        usort($book, static fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);
        foreach ($book as $rule) {
            $holds = array_filter($rule['conditions']) !== [] && array_filter($rule['conditions'], static fn (int $node, int $key): bool => $node !== 0 && ! in_array($node, $context[$key] ?? [], true), ARRAY_FILTER_USE_BOTH) === [];
            if ($holds && ($usable === null || $usable($rule['id']))) {
                return $rule['id'];
            }
        }

        return null;
    }

    /** As the catalog takes it. */
    public static function closure(): \Closure
    {
        return static fn (array $book, array $context, \Closure $usable, int $record = 0, array $fields = []): ?int => self::first($book, $context, $usable);
    }
}
