<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Drawing;

/**
 * A picture for a label — JPEG or PNG — read from its bytes, ready for the PDF and for the SVG.
 *
 * ⚠️ *A PNG is unpacked here, without GD, and laid on white: a label printer has no transparency, and a module must not depend
 * on an extension the server may lack.*
 *
 * @see docs/konzept.md §4
 */
final class Picture
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private function __construct(
        public readonly string $mime,
        public readonly int $widthPx,
        public readonly int $heightPx,
        private readonly string $bytes,
        /** For the PDF: filter, colour space, bits per component, stream data. */
        private readonly array $pdf,
    ) {
    }

    public static function fromBytes(string $bytes): self
    {
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return self::jpeg($bytes);
        }
        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return self::png($bytes);
        }

        throw new \InvalidArgumentException('A label picture is a JPEG or a PNG.');
    }

    public function dataUri(): string
    {
        return 'data:' . $this->mime . ';base64,' . base64_encode($this->bytes);
    }

    /** @return array{filter: string, space: string, bits: int, data: string} */
    public function forPdf(): array
    {
        return $this->pdf;
    }

    private static function jpeg(string $bytes): self
    {
        $offset = 2;
        $length = strlen($bytes);
        while ($offset + 9 < $length) {
            if ($bytes[$offset] !== "\xFF") {
                $offset++;
                continue;
            }
            $marker = ord($bytes[$offset + 1]);
            $size   = unpack('n', substr($bytes, $offset + 2, 2))[1];
            // SOF0..SOF15 carry the size, except DHT (C4), JPG (C8) and DAC (CC).
            if ($marker >= 0xC0 && $marker <= 0xCF && ! in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                $height     = unpack('n', substr($bytes, $offset + 5, 2))[1];
                $width      = unpack('n', substr($bytes, $offset + 7, 2))[1];
                $components = ord($bytes[$offset + 9]);
                $space      = [1 => '/DeviceGray', 3 => '/DeviceRGB', 4 => '/DeviceCMYK'][$components] ?? null;
                if ($space === null || $width < 1 || $height < 1) {
                    break;
                }

                return new self('image/jpeg', $width, $height, $bytes, ['filter' => '/DCTDecode', 'space' => $space, 'bits' => 8, 'data' => $bytes]);
            }
            $offset += 2 + $size;
        }

        throw new \InvalidArgumentException('The JPEG cannot be read.');
    }

    private static function png(string $bytes): self
    {
        $offset = 8;
        $idat   = '';
        $header = null;
        $palette = '';
        $length = strlen($bytes);
        while ($offset + 8 <= $length) {
            $size  = unpack('N', substr($bytes, $offset, 4))[1];
            $type  = substr($bytes, $offset + 4, 4);
            $chunk = substr($bytes, $offset + 8, $size);
            $offset += 12 + $size;
            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight/Cbits/Ccolour/Ccompression/Cfilter/Cinterlace', $chunk);
            } elseif ($type === 'PLTE') {
                $palette = $chunk;
            } elseif ($type === 'IDAT') {
                $idat .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if (! is_array($header) || $header['interlace'] !== 0 || ! in_array($header['colour'], [0, 2, 3, 4, 6], true)) {
            throw new \InvalidArgumentException('Only PNGs without interlacing can go on a label.');
        }
        $bits = $header['bits'];
        if ($bits !== 8 && ! ($header['colour'] === 3 && in_array($bits, [1, 2, 4], true)) && ! ($header['colour'] === 0 && in_array($bits, [1, 2, 4], true))) {
            throw new \InvalidArgumentException('Only 8-bit PNGs (or palette/grey with fewer bits) can go on a label.');
        }
        $raw = @gzuncompress($idat);
        if ($raw === false) {
            throw new \InvalidArgumentException('The PNG cannot be unpacked.');
        }

        $width    = $header['width'];
        $height   = $header['height'];
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$header['colour']];
        $stride   = (int) ceil($width * $channels * $bits / 8);
        $pixelBytes = max(1, intdiv($channels * $bits, 8));
        $rgb      = '';
        $previous = str_repeat("\0", $stride);
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$y * ($stride + 1)]);
            $line   = substr($raw, $y * ($stride + 1) + 1, $stride);
            $line   = self::unfilter($filter, $line, $previous, $pixelBytes);
            $previous = $line;
            for ($x = 0; $x < $width; $x++) {
                $rgb .= self::pixel($header['colour'], $bits, $line, $x, $palette);
            }
        }

        return new self('image/png', $width, $height, $bytes, ['filter' => '/FlateDecode', 'space' => '/DeviceRGB', 'bits' => 8, 'data' => (string) gzcompress($rgb)]);
    }

    private static function unfilter(int $filter, string $line, string $previous, int $bpp): string
    {
        $out = $line;
        $n   = strlen($line);
        for ($i = 0; $i < $n; $i++) {
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = ord($previous[$i]);
            $c = $i >= $bpp ? ord($previous[$i - $bpp]) : 0;
            $value = ord($line[$i]);
            $value += match ($filter) {
                0 => 0,
                1 => $a,
                2 => $b,
                3 => intdiv($a + $b, 2),
                4 => self::paeth($a, $b, $c),
                default => throw new \InvalidArgumentException('The PNG uses an unknown filter.'),
            };
            $out[$i] = chr($value & 0xFF);
        }

        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p  = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    /** One pixel as three RGB bytes, transparency laid on white. */
    private static function pixel(int $colour, int $bits, string $line, int $x, string $palette): string
    {
        if ($bits < 8) {
            $byte  = ord($line[intdiv($x * $bits, 8)]);
            $shift = 8 - $bits - ($x * $bits) % 8;
            $index = ($byte >> $shift) & ((1 << $bits) - 1);
            if ($colour === 3) {
                return substr($palette, $index * 3, 3) ?: "\0\0\0";
            }
            $grey = (int) round($index * 255 / ((1 << $bits) - 1));

            return str_repeat(chr($grey), 3);
        }
        $on = static function (int $value, int $alpha): string {
            return chr((int) round(($value * $alpha + 255 * (255 - $alpha)) / 255));
        };

        return match ($colour) {
            0 => str_repeat($line[$x], 3),
            2 => substr($line, $x * 3, 3),
            3 => substr($palette, ord($line[$x]) * 3, 3) ?: "\0\0\0",
            4 => str_repeat($on(ord($line[$x * 2]), ord($line[$x * 2 + 1])), 3),
            6 => $on(ord($line[$x * 4]), ord($line[$x * 4 + 3])) . $on(ord($line[$x * 4 + 1]), ord($line[$x * 4 + 3])) . $on(ord($line[$x * 4 + 2]), ord($line[$x * 4 + 3])),
        };
    }
}
