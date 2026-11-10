<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Code;

/**
 * A two-dimensional code as a grid of dark and light modules, row 0 at the top.
 *
 * @see docs/konzept.md §4
 */
interface Matrix
{
    public function width(): int;

    public function height(): int;

    public function isDark(int $x, int $y): bool;

    /** The light margin the standard asks for, in modules, on every side. */
    public function quietModules(): int;
}
