<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Code;

use InvalidArgumentException;

/**
 * A Data Matrix code (ISO/IEC 16022, ECC 200) in ASCII mode, square or, where asked, rectangular.
 *
 * ```mermaid
 * flowchart LR
 *   T["text"] --> A["ASCII codewords (digit pairs packed)"] --> P["pad to the symbol"] --> E["Reed-Solomon per block"]
 *   E --> U["place in the Utah pattern"] --> F["regions with finder and clock edges"]
 * ```
 *
 * @see docs/konzept.md §4
 */
final class DataMatrix implements Matrix
{
    /** rows, columns, region rows, region columns (inside the edges), data codewords, error codewords, blocks */
    private const SQUARE = [
        [10, 10, 8, 8, 3, 5, 1], [12, 12, 10, 10, 5, 7, 1], [14, 14, 12, 12, 8, 10, 1], [16, 16, 14, 14, 12, 12, 1],
        [18, 18, 16, 16, 18, 14, 1], [20, 20, 18, 18, 22, 18, 1], [22, 22, 20, 20, 30, 20, 1], [24, 24, 22, 22, 36, 24, 1],
        [26, 26, 24, 24, 44, 28, 1], [32, 32, 14, 14, 62, 36, 1], [36, 36, 16, 16, 86, 42, 1], [40, 40, 18, 18, 114, 48, 1],
        [44, 44, 20, 20, 144, 56, 1], [48, 48, 22, 22, 174, 68, 1], [52, 52, 24, 24, 204, 84, 2], [64, 64, 14, 14, 280, 112, 2],
    ];

    private const RECTANGLE = [
        [8, 18, 6, 16, 5, 7, 1], [8, 32, 6, 14, 10, 11, 1], [12, 26, 10, 24, 16, 14, 1], [12, 36, 10, 16, 22, 18, 1],
        [16, 36, 14, 16, 32, 24, 1], [16, 48, 14, 22, 49, 28, 1],
    ];

    private const PAD = 129;

    /** @var list<list<bool>> */
    private array $modules;

    /** @param array{0:int,1:int,2:int,3:int,4:int,5:int,6:int} $size */
    private function __construct(private readonly array $size, array $codewords)
    {
        [$rows, $cols, $regionRows, $regionCols] = $size;
        $this->modules = array_fill(0, $rows, array_fill(0, $cols, false));
        $mapRows = intdiv($rows, $regionRows + 2) * $regionRows;
        $mapCols = intdiv($cols, $regionCols + 2) * $regionCols;
        $map     = self::placement($mapRows, $mapCols);

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                $inY = $y % ($regionRows + 2);
                $inX = $x % ($regionCols + 2);
                if ($inX === 0 || $inY === $regionRows + 1) {
                    $this->modules[$y][$x] = true;
                } elseif ($inY === 0) {
                    $this->modules[$y][$x] = $x % 2 === 0;
                } elseif ($inX === $regionCols + 1) {
                    $this->modules[$y][$x] = $y % 2 === 1;
                } else {
                    $cell = $map[intdiv($y, $regionRows + 2) * $regionRows + $inY - 1][intdiv($x, $regionCols + 2) * $regionCols + $inX - 1];
                    $this->modules[$y][$x] = $cell === 1 || ($cell >= 10 && ($codewords[intdiv($cell, 10) - 1] & (1 << (8 - $cell % 10))) !== 0);
                }
            }
        }
    }

    /**
     * @param bool $rectangular also consider the rectangular sizes; the caller picks the shape that fits its box best
     * @return list<self> every symbol size that holds the text, smallest first
     */
    public static function candidates(string $text, bool $rectangular = false): array
    {
        $data  = self::ascii($text);
        $sizes = $rectangular ? [...self::RECTANGLE, ...self::SQUARE] : self::SQUARE;
        $out   = [];
        foreach ($sizes as $size) {
            if (count($data) <= $size[4]) {
                $out[] = new self($size, self::withErrorCorrection(self::padded($data, $size[4]), $size));
            }
        }
        if ($out === []) {
            throw new InvalidArgumentException(sprintf('Text of %d bytes is too long for a label Data Matrix code.', strlen($text)));
        }

        return $out;
    }

    public static function encode(string $text): self
    {
        return self::candidates($text)[0];
    }

    public function width(): int
    {
        return $this->size[1];
    }

    public function height(): int
    {
        return $this->size[0];
    }

    public function isDark(int $x, int $y): bool
    {
        return $this->modules[$y][$x];
    }

    /** ISO/IEC 16022: one module all round. */
    public function quietModules(): int
    {
        return 1;
    }

    /** @return list<int> */
    private static function ascii(string $text): array
    {
        if ($text === '') {
            throw new InvalidArgumentException('A Data Matrix code needs a text.');
        }
        $out    = [];
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($text[$i]);
            if ($i + 1 < $length && ctype_digit($text[$i] . $text[$i + 1])) {
                $out[] = 130 + (int) substr($text, $i, 2);
                $i++;
            } elseif ($byte < 128) {
                $out[] = $byte + 1;
            } else {
                $out[] = 235;
                $out[] = $byte - 127;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function padded(array $data, int $capacity): array
    {
        if (count($data) < $capacity) {
            $data[] = self::PAD;
        }
        while (count($data) < $capacity) {
            $random = self::PAD + ((149 * (count($data) + 1)) % 253) + 1;
            $data[] = $random > 254 ? $random - 254 : $random;
        }

        return $data;
    }

    /**
     * @param list<int> $data
     * @param array{0:int,1:int,2:int,3:int,4:int,5:int,6:int} $size
     * @return list<int>
     */
    private static function withErrorCorrection(array $data, array $size): array
    {
        $blocks    = $size[6];
        $perBlock  = intdiv($size[5], $blocks);
        $generator = (new ReedSolomon(0x12D, 1))->generatorPolynomial($perBlock);
        $out       = $data;
        for ($b = 0; $b < $blocks; $b++) {
            $block = [];
            for ($i = $b; $i < count($data); $i += $blocks) {
                $block[] = $data[$i];
            }
            foreach (ReedSolomon::remainderOf($block, $generator, 0x12D) as $k => $byte) {
                $out[count($data) + $b + $k * $blocks] = $byte;
            }
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * The ECC 200 placement: which codeword bit lands in each cell of the mapping matrix — `10 × codeword + bit`
     * (codewords from 1, bit 1 the highest), or 1 for the fixed dark corner.
     *
     * @return list<list<int>>
     */
    private static function placement(int $rows, int $cols): array
    {
        $map    = array_fill(0, $rows, array_fill(0, $cols, 0));
        $module = static function (int $row, int $col, int $chr, int $bit) use (&$map, $rows, $cols): void {
            if ($row < 0) {
                $row += $rows;
                $col += 4 - (($rows + 4) % 8);
            }
            if ($col < 0) {
                $col += $cols;
                $row += 4 - (($cols + 4) % 8);
            }
            $map[$row][$col] = 10 * $chr + $bit;
        };
        $utah = static function (int $row, int $col, int $chr) use ($module): void {
            $module($row - 2, $col - 2, $chr, 1);
            $module($row - 2, $col - 1, $chr, 2);
            $module($row - 1, $col - 2, $chr, 3);
            $module($row - 1, $col - 1, $chr, 4);
            $module($row - 1, $col, $chr, 5);
            $module($row, $col - 2, $chr, 6);
            $module($row, $col - 1, $chr, 7);
            $module($row, $col, $chr, 8);
        };
        $corner = static function (array $cells, int $chr) use ($module): void {
            foreach ($cells as $bit => [$row, $col]) {
                $module($row, $col, $chr, $bit + 1);
            }
        };

        $chr = 1;
        $row = 4;
        $col = 0;
        do {
            if ($row === $rows && $col === 0) {
                $corner([[$rows - 1, 0], [$rows - 1, 1], [$rows - 1, 2], [0, $cols - 2], [0, $cols - 1], [1, $cols - 1], [2, $cols - 1], [3, $cols - 1]], $chr++);
            }
            if ($row === $rows - 2 && $col === 0 && $cols % 4 !== 0) {
                $corner([[$rows - 3, 0], [$rows - 2, 0], [$rows - 1, 0], [0, $cols - 4], [0, $cols - 3], [0, $cols - 2], [0, $cols - 1], [1, $cols - 1]], $chr++);
            }
            if ($row === $rows - 2 && $col === 0 && $cols % 8 === 4) {
                $corner([[$rows - 3, 0], [$rows - 2, 0], [$rows - 1, 0], [0, $cols - 2], [0, $cols - 1], [1, $cols - 1], [2, $cols - 1], [3, $cols - 1]], $chr++);
            }
            if ($row === $rows + 4 && $col === 2 && $cols % 8 === 0) {
                $corner([[$rows - 1, 0], [$rows - 1, $cols - 1], [0, $cols - 3], [0, $cols - 2], [0, $cols - 1], [1, $cols - 3], [1, $cols - 2], [1, $cols - 1]], $chr++);
            }
            do {
                if ($row < $rows && $col >= 0 && $map[$row][$col] === 0) {
                    $utah($row, $col, $chr++);
                }
                $row -= 2;
                $col += 2;
            } while ($row >= 0 && $col < $cols);
            $row += 1;
            $col += 3;
            do {
                if ($row >= 0 && $col < $cols && $map[$row][$col] === 0) {
                    $utah($row, $col, $chr++);
                }
                $row += 2;
                $col -= 2;
            } while ($row < $rows && $col >= 0);
            $row += 3;
            $col += 1;
        } while ($row < $rows || $col < $cols);

        if ($map[$rows - 1][$cols - 1] === 0) {
            $map[$rows - 1][$cols - 1] = 1;
            $map[$rows - 2][$cols - 2] = 1;
        }

        return $map;
    }
}
