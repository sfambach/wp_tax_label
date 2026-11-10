<?php declare(strict_types=1);

namespace TaxmodLabel\Core\Pdf;

/**
 * A minimal PDF 1.4 writer: pages of any size in millimetres, filled rectangles, Helvetica text and pictures.
 *
 * Enough for labels and simple printouts (D-1032); no font is embedded, the two standard fonts
 * Helvetica and Helvetica-Bold are used with WinAnsi encoding, so umlauts print.
 *
 * @see wp-taxonomy-modeler docs/NewConcept/90-decision-log.md D-1032
 */
final class PdfDocument
{
    private const POINTS_PER_MM = 72 / 25.4;

    /** Width of Omega («W» in the Symbol font), thousandths of the font size. */
    private const SYMBOL_OMEGA_WIDTH = 768;

    /** @var list<array{width: float, height: float, turned: bool, content: string}> */
    private array $pages = [];

    /** @var list<array{filter: string, space: string, bits: int, data: string, width: int, height: int}> */
    private array $images = [];

    /**
     * A page drawn as width × height; turned, the sheet is height × width and the drawing is laid on it a quarter turn.
     */
    public function addPage(float $widthMm, float $heightMm, bool $turned = false): void
    {
        $width = $widthMm * self::POINTS_PER_MM;
        $height = $heightMm * self::POINTS_PER_MM;
        $this->pages[] = [
            'width' => $width,
            'height' => $height,
            'turned' => $turned,
            'content' => $turned ? sprintf("0 1 -1 0 %s 0 cm\n", self::number($height)) : '',
        ];
    }

    /** D-1190: what follows is drawn moved sideways by $dxMm until {@see self::endShift()}. */
    public function beginShift(float $dxMm): void
    {
        $this->append(sprintf("q 1 0 0 1 %s 0 cm\n", self::number($dxMm * self::POINTS_PER_MM)));
    }

    public function endShift(): void
    {
        $this->append("Q\n");
    }

    /**
     * Filled black rectangles as one path, so touching rectangles show no hairline seams; origin top left, millimetres.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $rectangles x, y, width, height
     */
    public function rectangles(array $rectangles): void
    {
        $page = $this->currentHeight();
        $path = '';
        foreach ($rectangles as [$xMm, $yMm, $widthMm, $heightMm]) {
            $path .= sprintf(
                "%s %s %s %s re\n",
                self::number($xMm * self::POINTS_PER_MM),
                self::number($page - ($yMm + $heightMm) * self::POINTS_PER_MM),
                self::number($widthMm * self::POINTS_PER_MM),
                self::number($heightMm * self::POINTS_PER_MM),
            );
        }
        if ($path !== '') {
            $this->append($path . "f\n");
        }
    }

    /** Text with its baseline at $baselineMm from the top; UTF-8 in, Ω drawn from the Symbol font. */
    public function text(float $xMm, float $baselineMm, string $text, float $sizePt, bool $bold = false): void
    {
        $page = $this->currentHeight();
        $shows = '';
        foreach (self::runs($text) as [$symbol, $run]) {
            $shows .= sprintf('/%s %s Tf (%s) Tj ', $symbol ? 'F3' : ($bold ? 'F2' : 'F1'), self::number($sizePt), self::escape($symbol ? str_repeat('W', mb_strlen($run)) : self::winAnsi($run)));
        }
        $this->append(sprintf(
            "BT %s %s Td %sET\n",
            self::number($xMm * self::POINTS_PER_MM),
            self::number($page - $baselineMm * self::POINTS_PER_MM),
            $shows,
        ));
    }

    /**
     * A picture stretched over the box; origin top left, millimetres.
     *
     * @param array{filter: string, space: string, bits: int, data: string} $image as {@see \TaxmodLabel\Core\Drawing\Picture::forPdf()}
     */
    public function image(float $xMm, float $yMm, float $widthMm, float $heightMm, array $image, int $widthPx, int $heightPx): void
    {
        $page = $this->currentHeight();
        $this->images[] = $image + ['width' => $widthPx, 'height' => $heightPx];
        $this->append(sprintf(
            "q %s 0 0 %s %s %s cm /Im%d Do Q\n",
            self::number($widthMm * self::POINTS_PER_MM),
            self::number($heightMm * self::POINTS_PER_MM),
            self::number($xMm * self::POINTS_PER_MM),
            self::number($page - ($yMm + $heightMm) * self::POINTS_PER_MM),
            count($this->images),
        ));
    }

    /** Width of UTF-8 text in millimetres. */
    public static function textWidthMm(string $text, float $sizePt, bool $bold = false): float
    {
        $points = 0.0;
        foreach (self::runs($text) as [$symbol, $run]) {
            $points += $symbol ? mb_strlen($run) * self::SYMBOL_OMEGA_WIDTH * $sizePt / 1000 : HelveticaMetrics::width(self::winAnsi($run), $sizePt, $bold);
        }
        return $points / self::POINTS_PER_MM;
    }

    /**
     * The text cut into runs for Helvetica and for the Symbol font, which alone carries the ohm sign.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private static function runs(string $text): array
    {
        $runs = [];
        foreach (preg_split('/([\x{03A9}\x{2126}]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $runs[] = [preg_match('/^[\x{03A9}\x{2126}]+$/u', $part) === 1, $part];
        }
        return $runs;
    }

    public static function pointsToMm(float $points): float
    {
        return $points / self::POINTS_PER_MM;
    }

    public function render(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Symbol >>',
        ];
        $kids = [];
        $next = 6;
        $xObjects = '';
        foreach ($this->images as $i => $image) {
            $objects[$next] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent %d /Filter %s /Length %d >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                $image['space'],
                $image['bits'],
                $image['filter'],
                strlen($image['data']),
                $image['data'],
            );
            $xObjects .= sprintf(' /Im%d %d 0 R', $i + 1, $next++);
        }
        $resources = '/Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>' . ($xObjects === '' ? '' : ' /XObject <<' . $xObjects . ' >>');
        foreach ($this->pages as $page) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << %s >> /Contents %d 0 R >>',
                self::number($page['turned'] ? $page['height'] : $page['width']),
                self::number($page['turned'] ? $page['width'] : $page['height']),
                $resources,
                $contentId,
            );
            $objects[$contentId] = sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($page['content']), $page['content']);
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }

    private function currentHeight(): float
    {
        if ($this->pages === []) {
            throw new \LogicException('Add a page before drawing on it.');
        }
        return $this->pages[count($this->pages) - 1]['height'];
    }

    private function append(string $operators): void
    {
        $this->pages[count($this->pages) - 1]['content'] .= $operators;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }

    /**
     * Signs WinAnsi lacks, written with the nearest it has — without this they print as «?» (his find 2026-11-06: «inch wird
     * … als ? ausgedruckt»; the computed display names write the inch as ″).
     */
    private const STAND_INS = [
        '″' => '"', '′' => "'", '‴' => "'''", '−' => '-', '‐' => '-', '‑' => '-', '‒' => '-', '⁄' => '/', '∕' => '/',
        "\u{2009}" => ' ', "\u{202F}" => ' ', "\u{2007}" => ' ', "\u{200B}" => '', '≤' => '<=', '≥' => '>=', '≈' => '~', '→' => '->', '←' => '<-',
    ];

    private static function winAnsi(string $utf8): string
    {
        return mb_convert_encoding(strtr($utf8, self::STAND_INS), 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
    }
}
