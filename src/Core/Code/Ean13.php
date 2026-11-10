<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Code;

use InvalidArgumentException;

/**
 * EAN-13 (ISO/IEC 15420): twelve digits and a check digit — given twelve, the check is added; given thirteen, it must match.
 *
 * @see docs/konzept.md §4
 */
final class Ean13 implements Bars
{
    /** Space, bar, space, bar for the left-hand odd set; the right-hand set has the same widths starting with a bar. */
    private const L = ['3211', '2221', '2122', '1411', '1132', '1231', '1114', '1312', '1213', '3112'];

    /** Which of the six left digits use the even set (G = L reversed), by the first digit. */
    private const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    private function __construct(private readonly string $digits)
    {
    }

    public static function encode(string $text): self
    {
        $text = trim($text);
        if (preg_match('/^\d{12,13}$/', $text) !== 1) {
            throw new InvalidArgumentException('EAN-13 takes 12 or 13 digits.');
        }
        $check = self::check(substr($text, 0, 12));
        if (strlen($text) === 13 && (int) $text[12] !== $check) {
            throw new InvalidArgumentException(sprintf('The check digit of this EAN-13 is %d, not %s.', $check, $text[12]));
        }

        return new self(substr($text, 0, 12) . $check);
    }

    public static function check(string $twelve): int
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    public function widths(): array
    {
        $out    = [1, 1, 1];
        $parity = self::PARITY[(int) $this->digits[0]];
        for ($i = 1; $i <= 6; $i++) {
            $pattern = self::L[(int) $this->digits[$i]];
            $out     = [...$out, ...self::split($parity[$i - 1] === 'G' ? strrev($pattern) : $pattern)];
        }
        $out = [...$out, 1, 1, 1, 1, 1];
        for ($i = 7; $i <= 12; $i++) {
            $out = [...$out, ...self::split(self::L[(int) $this->digits[$i]])];
        }

        return [...$out, 1, 1, 1];
    }

    public function quietModules(): int
    {
        return 11;
    }

    public function humanText(): string
    {
        return $this->digits;
    }

    /** @return list<int> */
    private static function split(string $pattern): array
    {
        return array_map('intval', str_split($pattern));
    }
}
