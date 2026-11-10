<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Code;

/**
 * A one-dimensional code as alternating bars and spaces, each a whole number of narrow modules, starting with a bar.
 *
 * @see docs/konzept.md §4
 */
interface Bars
{
    /** @return list<int> widths in narrow modules: bar, space, bar, … */
    public function widths(): array;

    /** The light margin left and right, in narrow modules. */
    public function quietModules(): int;

    /** What a person reads under the bars. */
    public function humanText(): string;
}
