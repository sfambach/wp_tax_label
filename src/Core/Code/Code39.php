<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Code;

use InvalidArgumentException;

/**
 * Code 39 (ISO/IEC 16388): digits, capitals and `- . $ / + %` and space, framed by `*`, wide elements three times narrow.
 *
 * @see docs/konzept.md §4
 */
final class Code39 implements Bars
{
    /** Nine elements per sign, bar first: n narrow, w wide. */
    private const PATTERNS = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    private const WIDE = 3;

    private function __construct(private readonly string $text)
    {
    }

    public static function encode(string $text): self
    {
        if ($text === '' || preg_match('/^[0-9A-Z\-. $\/+%]+$/', $text) !== 1) {
            throw new InvalidArgumentException('Code 39 takes 0–9, A–Z (capitals), space and - . $ / + % only.');
        }

        return new self($text);
    }

    public function widths(): array
    {
        $out = [];
        foreach (str_split('*' . $this->text . '*') as $i => $sign) {
            if ($i > 0) {
                $out[] = 1;
            }
            foreach (str_split(self::PATTERNS[$sign]) as $element) {
                $out[] = $element === 'w' ? self::WIDE : 1;
            }
        }

        return $out;
    }

    public function quietModules(): int
    {
        return 10;
    }

    public function humanText(): string
    {
        return $this->text;
    }
}
