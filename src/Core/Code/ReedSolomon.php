<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Code;

/**
 * Reed-Solomon error correction over GF(256) — the QR polynomial 0x11D with roots from α⁰ by default, Data Matrix with
 * 0x12D and roots from α¹.
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1032
 */
final class ReedSolomon
{
    private const QR = 0x11D;

    public function __construct(private readonly int $polynomial = self::QR, private readonly int $firstRoot = 0)
    {
    }

    /**
     * QR form: coefficients of the generator polynomial, highest power omitted.
     *
     * @return list<int>
     */
    public static function generator(int $degree): array
    {
        return (new self())->generatorPolynomial($degree);
    }

    /** @return list<int> */
    public function generatorPolynomial(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $this->firstRoot; $i++) {
            $root = self::times($root, 0x02, $this->polynomial);
        }
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::times($result[$j], $root, $this->polynomial);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::times($root, 0x02, $this->polynomial);
        }

        return $result;
    }

    /**
     * @param list<int> $data
     * @param list<int> $generator
     * @return list<int>
     */
    public static function remainder(array $data, array $generator): array
    {
        return self::remainderOf($data, $generator, self::QR);
    }

    /**
     * @param list<int> $data
     * @param list<int> $generator
     * @return list<int>
     */
    public static function remainderOf(array $data, array $generator, int $polynomial): array
    {
        $result = array_fill(0, count($generator), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($generator as $i => $coefficient) {
                $result[$i] ^= self::times($coefficient, $factor, $polynomial);
            }
        }

        return $result;
    }

    public static function multiply(int $x, int $y): int
    {
        return self::times($x, $y, self::QR);
    }

    private static function times(int $x, int $y, int $polynomial): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * $polynomial);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }
}
