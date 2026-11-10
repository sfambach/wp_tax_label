<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Code;

use InvalidArgumentException;

/**
 * A QR code (ISO/IEC 18004, byte mode, error correction M) as a square grid of dark and light modules.
 *
 * Labels carry only a short prefixed id such as `O-123` (D-1032), so versions 1 to 10 are enough
 * and no external library is pulled into a plugin that has no runtime dependencies.
 *
 * ```mermaid
 * flowchart LR
 *   T["text"] --> D["data codewords"] --> E["Reed-Solomon per block"] --> I["interleave"]
 *   I --> P["place in zigzag"] --> M["best of 8 masks"] --> F["format + version bits"]
 * ```
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1032
 */
final class QrCode implements Matrix
{
    /** Per version (index 1..10): [ec codewords per block, [[block count, data codewords], ...]] for level M. */
    private const BLOCKS_M = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** Format bits for level M are 00. */
    private const LEVEL_M_BITS = 0;

    /** @var list<list<bool>> */
    private array $modules;

    /** @var list<list<bool>> */
    private array $reserved;

    private int $size;

    private function __construct(public readonly int $version)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->reserved = array_fill(0, $this->size, $row);
    }

    public static function encode(string $text): self
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = self::smallestVersion(count($bytes));
        $code = new self($version);
        $code->drawFunctionPatterns();
        $codewords = $code->addErrorCorrection(self::dataCodewords($bytes, $version));
        $code->placeCodewords($codewords);
        $code->applyBestMask();
        return $code;
    }

    public function width(): int
    {
        return $this->size;
    }

    public function height(): int
    {
        return $this->size;
    }

    /** ISO/IEC 18004: four light modules all round. */
    public function quietModules(): int
    {
        return 4;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isDark(int $x, int $y): bool
    {
        return $this->modules[$y][$x];
    }

    private static function capacity(int $version): int
    {
        $total = 0;
        foreach (self::BLOCKS_M[$version][1] as [$count, $data]) {
            $total += $count * $data;
        }
        return $total;
    }

    private static function countBits(int $version): int
    {
        return $version < 10 ? 8 : 16;
    }

    private static function smallestVersion(int $length): int
    {
        foreach (array_keys(self::BLOCKS_M) as $version) {
            if (4 + self::countBits($version) + 8 * $length <= self::capacity($version) * 8) {
                return $version;
            }
        }
        throw new InvalidArgumentException(sprintf('Text of %d bytes is too long for a label QR code.', $length));
    }

    /**
     * @param list<int> $bytes
     * @return list<int>
     */
    private static function dataCodewords(array $bytes, int $version): array
    {
        $bits = [];
        $push = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $push(0b0100, 4);
        $push(count($bytes), self::countBits($version));
        foreach ($bytes as $byte) {
            $push($byte, 8);
        }
        $capacityBits = self::capacity($version) * 8;
        $push(0, min(4, $capacityBits - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $codewords = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $codewords[] = (int) bindec(implode('', $chunk));
        }
        for ($pad = 0xEC; count($codewords) < self::capacity($version); $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }
        return $codewords;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private function addErrorCorrection(array $data): array
    {
        [$ecLength, $groups] = self::BLOCKS_M[$this->version];
        $divisor = ReedSolomon::generator($ecLength);
        $blocks = [];
        $offset = 0;
        foreach ($groups as [$count, $length]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($data, $offset, $length);
                $offset += $length;
                $blocks[] = [$chunk, ReedSolomon::remainder($chunk, $divisor)];
            }
        }
        $result = [];
        $longest = max(array_map(static fn (array $block): int => count($block[0]), $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as [$chunk]) {
                if (isset($chunk[$i])) {
                    $result[] = $chunk[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLength; $i++) {
            foreach ($blocks as [, $ec]) {
                $result[] = $ec[$i];
            }
        }
        return $result;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->reserved[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);
        $positions = self::ALIGNMENT[$this->version];
        $last = count($positions) - 1;
        foreach ($positions as $i => $cx) {
            foreach ($positions as $j => $cy) {
                $nearFinder = ($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0);
                if (!$nearFinder) {
                    $this->drawAlignment($cx, $cy);
                }
            }
        }
        $this->drawFormatBits(0);
        $this->drawVersionBits();
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
                    continue;
                }
                $distance = max(abs($dx), abs($dy));
                $this->set($x, $y, $distance !== 2 && $distance !== 4);
            }
        }
    }

    private function drawAlignment(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::LEVEL_M_BITS << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }
        $this->set(8, $this->size - 8, true);
    }

    private function drawVersionBits(): void
    {
        if ($this->version < 7) {
            return;
        }
        $remainder = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $remainder;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->set($a, $b, $dark);
            $this->set($b, $a, $dark);
        }
    }

    /**
     * @param list<int> $codewords
     */
    private function placeCodewords(array $codewords): void
    {
        $bitIndex = 0;
        $totalBits = count($codewords) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;
                    if ($this->reserved[$y][$x]) {
                        continue;
                    }
                    if ($bitIndex < $totalBits) {
                        $byte = $codewords[$bitIndex >> 3];
                        $this->modules[$y][$x] = (($byte >> (7 - ($bitIndex & 7))) & 1) === 1;
                        $bitIndex++;
                    }
                }
            }
        }
    }

    private static function maskApplies(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
        };
    }

    private function toggleMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if (!$this->reserved[$y][$x] && self::maskApplies($mask, $x, $y)) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function applyBestMask(): void
    {
        $best = 0;
        $lowest = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->toggleMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $lowest) {
                $lowest = $penalty;
                $best = $mask;
            }
            $this->toggleMask($mask);
        }
        $this->toggleMask($best);
        $this->drawFormatBits($best);
    }

    private function penalty(): int
    {
        $penalty = 0;
        $lines = [];
        for ($i = 0; $i < $this->size; $i++) {
            $row = [];
            $column = [];
            for ($j = 0; $j < $this->size; $j++) {
                $row[] = $this->modules[$i][$j];
                $column[] = $this->modules[$j][$i];
            }
            $lines[] = $row;
            $lines[] = $column;
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($j = 1; $j <= $this->size; $j++) {
                if ($j < $this->size && $line[$j] === $line[$j - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $penalty += $run - 2;
                }
                $run = 1;
            }
            $pattern = implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $line));
            $penalty += 40 * (substr_count($pattern, '10111010000') + substr_count($pattern, '00001011101'));
        }
        $dark = 0;
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $dark += $this->modules[$y][$x] ? 1 : 0;
                if ($x > 0 && $y > 0) {
                    $same = $this->modules[$y][$x];
                    if ($this->modules[$y - 1][$x] === $same && $this->modules[$y][$x - 1] === $same && $this->modules[$y - 1][$x - 1] === $same) {
                        $penalty += 3;
                    }
                }
            }
        }
        $total = $this->size * $this->size;
        $penalty += 10 * intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 10;
        return $penalty;
    }
}
