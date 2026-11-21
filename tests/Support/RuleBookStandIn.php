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
     * @param list<array{id: int, position: int, conditions: array<int, int>, filters?: list<array{field: int, value: string, operator?: string}>}> $book
     * @param array<int, list<int>>    $context
     * @param array<int, list<string>> $values field → the thing's values; a filter holds when its value is among them (the book's
     *                                         «gleich», and «liegt unter» for values handed in with every place above)
     */
    public static function first(array $book, array $context, ?\Closure $usable = null, array $values = []): ?int
    {
        usort($book, static fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);
        foreach ($book as $rule) {
            $holds = array_filter($rule['conditions']) !== [] && array_filter($rule['conditions'], static fn (int $node, int $key): bool => $node !== 0 && ! in_array($node, $context[$key] ?? [], true), ARRAY_FILTER_USE_BOTH) === [];
            foreach ($rule['filters'] ?? [] as $filter) {
                $holds = $holds && in_array($filter['value'], $values[$filter['field']] ?? [], true);
            }
            if ($holds && ($usable === null || $usable($rule['id']))) {
                return $rule['id'];
            }
        }

        return null;
    }

    /**
     * As the catalog takes it; `$materials` stands in for the material values the boundary reads.
     *
     * @param array<int, array<int, list<string>>> $materials record → field → values
     */
    public static function closure(array $materials = []): \Closure
    {
        return static fn (array $book, array $context, \Closure $usable, int $record = 0, array $fields = [], array $stock = []): ?int => self::first(
            $book,
            $context,
            $usable,
            \TaxmodLabel\Core\PrintRules::valuesWith(array_intersect_key($materials[$record] ?? [], array_flip($fields)), $stock)
        );
    }
}
