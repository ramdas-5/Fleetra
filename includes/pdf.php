<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/pdf.php
 *
 * A tiny, dependency-free PDF writer. It emits a real PDF 1.4 document
 * using the built-in Helvetica core fonts, so tickets download and print
 * as proper PDFs without a screenshot or an external library.
 *
 * Coordinate system: the API uses top-left origin (0,0 = top-left of the
 * page) because that is how designers think about layout; the writer
 * converts to PDF's bottom-left origin internally.
 */

declare(strict_types=1);

/** A4 page size in points. */
const PDF_A4_WIDTH  = 595.28;
const PDF_A4_HEIGHT = 841.89;

final class FleetraPdf
{
    /** @var array<int, string> Finished content streams, one per page. */
    private array $pages = [];

    private string $buffer = '';

    private float $width;
    private float $height;

    private string $font = 'F1';
    private float $fontSize = 10.0;

    /** @var array{0:float,1:float,2:float} */
    private array $color = [0.06, 0.07, 0.13];

    public function __construct(float $width = PDF_A4_WIDTH, float $height = PDF_A4_HEIGHT)
    {
        $this->width  = $width;
        $this->height = $height;
        $this->addPage();
    }

    /** Start a new page, finishing the current one. */
    public function addPage(): void
    {
        if ($this->buffer !== '') {
            $this->pages[] = $this->buffer;
        }

        $this->buffer = '';
    }

    /** Choose a core font. $bold = use Helvetica-Bold. */
    public function setFont(float $size, bool $bold = false): void
    {
        $this->font     = $bold ? 'F2' : 'F1';
        $this->fontSize = $size;
    }

    public function setColor(float $r, float $g, float $b): void
    {
        $this->color = [$r, $g, $b];
    }

    /** Approximate width of a string at the current font size. */
    public function textWidth(string $text): float
    {
        // 0.5 em average for Helvetica, a little wider for bold.
        $factor = $this->font === 'F2' ? 0.56 : 0.5;

        return mb_strlen($text) * $this->fontSize * $factor;
    }

    /** Draw text at a top-left position. */
    public function text(float $x, float $topY, string $text, ?float $size = null, bool $bold = false): void
    {
        if ($size !== null || $bold) {
            $this->setFont($size ?? $this->fontSize, $bold);
        }

        $y = $this->height - $topY - $this->fontSize;

        [$r, $g, $b] = $this->color;

        $this->buffer .= sprintf(
            "BT %.3f %.3f %.3f rg /%s %.2f Tf %.3f %.3f Td (%s) Tj ET\n",
            $r,
            $g,
            $b,
            $this->font,
            $this->fontSize,
            $x,
            $y,
            $this->escape($text)
        );
    }

    /** Draw right-aligned text ending at $rightX. */
    public function textRight(float $rightX, float $topY, string $text, ?float $size = null, bool $bold = false): void
    {
        $size ??= $this->fontSize;
        $factor = $bold ? 0.56 : 0.5;
        $width  = mb_strlen($text) * $size * $factor;

        $this->text($rightX - $width, $topY, $text, $size, $bold);
    }

    /** Filled or outlined rectangle from a top-left origin. */
    public function rect(float $x, float $topY, float $w, float $h, bool $filled = true): void
    {
        [$r, $g, $b] = $this->color;
        $y = $this->height - $topY - $h;

        $this->buffer .= sprintf(
            "%.3f %.3f %.3f %s %.3f %.3f %.3f %.3f re %s\n",
            $r,
            $g,
            $b,
            $filled ? 'rg' : 'RG',
            $x,
            $y,
            $w,
            $h,
            $filled ? 'f' : 'S'
        );
    }

    /** Straight line between two top-left points. */
    public function line(float $x1, float $topY1, float $x2, float $topY2, float $lineWidth = 0.7): void
    {
        [$r, $g, $b] = $this->color;
        $y1 = $this->height - $topY1;
        $y2 = $this->height - $topY2;

        $this->buffer .= sprintf(
            "%.3f %.3f %.3f RG %.2f w %.3f %.3f m %.3f %.3f l S\n",
            $r,
            $g,
            $b,
            $lineWidth,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    /**
     * Draw a Code 39 barcode (widely readable by scanners) for $data.
     * Returns the width consumed.
     */
    public function barcodeCode39(float $x, float $topY, string $data, float $height = 42, float $narrow = 1.4): float
    {
        $patterns = self::code39Patterns();
        $data     = strtoupper($data);
        $chars    = '*' . $data . '*';

        $cursor = $x;
        $wide   = $narrow * 2.6;

        for ($i = 0, $len = strlen($chars); $i < $len; $i++) {
            $char = $chars[$i];

            if (!isset($patterns[$char])) {
                continue;
            }

            $pattern = $patterns[$char];

            // 9 elements: bars at even indexes, spaces at odd indexes.
            for ($e = 0; $e < 9; $e++) {
                $width = $pattern[$e] === 'w' ? $wide : $narrow;

                if ($e % 2 === 0) {
                    $this->rect($cursor, $topY, $width, $height, true);
                }

                $cursor += $width;
            }

            // Inter-character gap (narrow space).
            $cursor += $narrow;
        }

        return $cursor - $x;
    }

    /** @return array<string, string> */
    private static function code39Patterns(): array
    {
        return [
            '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
            '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
            '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
            'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
            'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
            'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
            'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
            'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
            'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
            '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
            '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
        ];
    }

    /** Finish the document and return the raw PDF bytes. */
    public function output(): string
    {
        if ($this->buffer !== '' || $this->pages === []) {
            $this->pages[] = $this->buffer;
            $this->buffer = '';
        }

        $objects   = [];
        $pageCount = count($this->pages);

        // Object 1 = catalog, 2 = pages tree, then per page: page + contents.
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (5 + $i * 2) . ' 0 R';
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        for ($i = 0; $i < $pageCount; $i++) {
            $pageObj     = 5 + $i * 2;
            $contentObj  = $pageObj + 1;
            $content     = $this->pages[$i];

            $objects[$pageObj] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->width,
                $this->height,
                $contentObj
            );

            $objects[$contentObj] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
        }

        ksort($objects);

        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxObject  = max(array_keys($objects));

        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($n = 1; $n <= $maxObject; $n++) {
            if (isset($offsets[$n])) {
                $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
            } else {
                $pdf .= "0000000000 65535 f \n";
            }
        }

        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    /** Escape a string for a PDF literal string. */
    private function escape(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(['(', ')'], ['\\(', '\\)'], $text);

        // Convert UTF-8 to Windows-1252 so the core fonts render it.
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $converted === false ? $text : $converted;
    }
}
