<?php

namespace App\Support\Xlsx;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * One worksheet. Rows are appended in order; a cell is a plain value or
 * XlsxSheet::cell($value, $style).
 *
 * Values: string (stored as text, never evaluated as a formula), int/float
 * (number), DateTimeInterface (an Excel date, use a date style), null (an
 * empty cell that still carries its style, e.g. a border).
 */
class XlsxSheet
{
    /** Excel's limit for the text of one cell. */
    private const MAX_CELL_TEXT = 32767;

    private const MIN_WIDTH = 6;

    private const MAX_WIDTH = 60;

    /** Long headers wrap instead of widening their column past this. */
    private const MAX_HEADER_WIDTH = 28;

    /** Height of one line of 11pt Calibri, in points. */
    private const LINE_HEIGHT = 15;

    /** Space above and below the text of a data row, in points. */
    private const ROW_PADDING = 7;

    /** Excel's largest row height, in points. */
    private const MAX_HEIGHT = 409;

    /**
     * Row number => cell XML, a fixed height (null: fitted to its text), and
     * the line lengths of its wrapping cells (column => lengths), used to fit
     * the height once the column widths are known.
     *
     * @var array<int, array{xml: string, height: ?float, header: bool, lines: array<int, list<float>>}>
     */
    private array $rows = [];

    private int $rowCount = 0;

    private int $columnCount = 0;

    /** @var array<int, float> column number => measured width */
    private array $widths = [];

    /** @var array<int, float> column number => fixed width */
    private array $fixedWidths = [];

    private int $frozenRows = 0;

    private ?string $autoFilter = null;

    /** @var list<array{ref: string, location: string, display: string}> */
    private array $links = [];

    public function __construct(public readonly string $name) {}

    /**
     * @return array{value: mixed, style: string}
     */
    public static function cell(mixed $value, string $style = 'text'): array
    {
        if (! array_key_exists($style, XlsxWorkbook::STYLES)) {
            throw new InvalidArgumentException("Unknown cell style [{$style}].");
        }

        return ['value' => $value, 'style' => $style];
    }

    /**
     * Append a row and return its number (1-based).
     *
     * @param  list<mixed>  $cells
     * @param  bool  $measure  whether the row counts for automatic column widths
     * @param  bool  $header  measured as a header: long labels wrap
     * @param  bool  $striped  shaded band (every other data row)
     * @param  float|null  $height  fixed height in points; null fits wrapped text
     */
    public function addRow(array $cells, bool $measure = true, bool $header = false, bool $striped = false, ?float $height = null): int
    {
        $row = ++$this->rowCount;
        $xml = '';
        $lines = [];

        foreach (array_values($cells) as $index => $cell) {
            $column = $index + 1;
            ['value' => $value, 'style' => $style] = is_array($cell) && array_key_exists('style', $cell)
                ? $cell
                : self::cell($cell, is_int($cell) || is_float($cell) ? 'number' : 'text');

            if ($striped && array_key_exists($style.'_alt', XlsxWorkbook::STYLES)) {
                $style .= '_alt';
            }

            $xml .= $this->cellXml(self::columnName($column).$row, $value, XlsxWorkbook::STYLES[$style]);

            if (in_array($style, XlsxWorkbook::WRAPPING, true) && (is_string($value) || is_bool($value)) && $value !== '') {
                $factor = $header ? 1.05 : 1;
                $lines[$column] = array_map(fn (string $line) => mb_strlen($line) * $factor, explode("\n", is_bool($value) ? 'Tidak' : $value));
            }

            if ($measure) {
                $width = $this->measure($value, $style);
                $width = $header ? min($width, self::MAX_HEADER_WIDTH) : $width;
                $this->widths[$column] = max($this->widths[$column] ?? 0, $width);
            }
        }

        $this->columnCount = max($this->columnCount, count($cells));
        $this->rows[$row] = ['xml' => $xml, 'height' => $height, 'header' => $header, 'lines' => $lines];

        return $row;
    }

    public function blankRow(?float $height = null): int
    {
        return $this->addRow([], false, height: $height);
    }

    public function setWidth(int $column, float $width): void
    {
        $this->fixedWidths[$column] = $width;
    }

    /** Keep the first $rows rows in view while scrolling. */
    public function freezeRows(int $rows): void
    {
        $this->frozenRows = $rows;
    }

    /** Filter buttons on a header row, covering the data below it. */
    public function autoFilter(int $headerRow, int $columns, int $lastRow): void
    {
        $this->autoFilter = 'A'.$headerRow.':'.self::columnName(max(1, $columns)).max($headerRow, $lastRow);
    }

    public function autoFilterRange(): ?string
    {
        return $this->autoFilter;
    }

    /** Link a cell to cell A1 of another sheet of the workbook. */
    public function linkToSheet(int $row, int $column, string $sheetName, string $display): void
    {
        $this->links[] = ['ref' => self::columnName($column).$row, 'location' => self::quote($sheetName).'!A1', 'display' => $display];
    }

    /** Rows repeated at the top of every printed page: the frozen rows. */
    public function printTitlesReference(): ?string
    {
        return $this->frozenRows > 0
            ? self::quote($this->name).'!$1:$'.$this->frozenRows
            : null;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    /** "'Sheet name'!$A$1:$C$9" for a plain range like A1:C9. */
    public function reference(string $range): string
    {
        return self::quote($this->name).'!'.preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $range);
    }

    public static function columnName(int $column): string
    {
        $name = '';

        for (; $column > 0; $column = intdiv($column - 1, 26)) {
            $name = chr(65 + ($column - 1) % 26).$name;
        }

        return $name;
    }

    /**
     * Excel serial date: days since 1899-12-30, the time of day as fraction.
     */
    public static function serial(DateTimeInterface $date): float
    {
        // Calendar arithmetic in UTC, so the server time zone and DST never shift a day.
        $midnight = gmmktime(0, 0, 0, (int) $date->format('n'), (int) $date->format('j'), (int) $date->format('Y'));
        $days = intdiv($midnight - gmmktime(0, 0, 0, 12, 30, 1899), 86400);
        $seconds = (int) $date->format('G') * 3600 + (int) $date->format('i') * 60 + (int) $date->format('s');

        return $days + $seconds / 86400;
    }

    public function toXml(): string
    {
        // Borders and bands mark the table; gridlines elsewhere are noise.
        $views = '<sheetViews><sheetView showGridLines="0" workbookViewId="0">';

        if ($this->frozenRows > 0) {
            $topLeft = 'A'.($this->frozenRows + 1);
            $views .= '<pane ySplit="'.$this->frozenRows.'" topLeftCell="'.$topLeft.'" activePane="bottomLeft" state="frozen"/>'
                .'<selection pane="bottomLeft" activeCell="'.$topLeft.'" sqref="'.$topLeft.'"/>';
        }

        $views .= '</sheetView></sheetViews>';

        $columns = '';
        $widths = [];
        for ($column = 1; $column <= $this->columnCount; $column++) {
            $widths[$column] = $this->fixedWidths[$column] ?? min(self::MAX_WIDTH, max(self::MIN_WIDTH, ($this->widths[$column] ?? 0) + 2));
            $columns .= '<col min="'.$column.'" max="'.$column.'" width="'.round($widths[$column], 2).'" customWidth="1"/>';
        }

        $rows = '';
        foreach ($this->rows as $number => $row) {
            $height = $row['height'] ?? $this->fittedHeight($row, $widths);
            $rows .= '<row r="'.$number.'"'.($height !== null ? ' ht="'.round($height, 2).'" customHeight="1"' : '').'>'.$row['xml'].'</row>';
        }

        $links = '';
        foreach ($this->links as $link) {
            $links .= '<hyperlink ref="'.$link['ref'].'" location="'.XlsxWorkbook::escape($link['location']).'" display="'.XlsxWorkbook::escape($link['display']).'"/>';
        }

        $dimension = $this->rowCount > 0 && $this->columnCount > 0 ? 'A1:'.self::columnName($this->columnCount).$this->rowCount : 'A1';

        // Element order is fixed by the SpreadsheetML schema.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<dimension ref="'.$dimension.'"/>'
            .$views
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .($columns !== '' ? '<cols>'.$columns.'</cols>' : '')
            .'<sheetData>'.$rows.'</sheetData>'
            .($this->autoFilter ? '<autoFilter ref="'.$this->autoFilter.'"/>' : '')
            .($links !== '' ? '<hyperlinks>'.$links.'</hyperlinks>' : '')
            .'<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            // Footer: sheet name on the left, page numbers on the right.
            .'<headerFooter><oddFooter>'.XlsxWorkbook::escape('&L&9&A&R&9Halaman &P dari &N').'</oddFooter></headerFooter>'
            .'</worksheet>';
    }

    /**
     * Height that shows every line of the row's wrapped cells at the final
     * column widths; null keeps Excel's default for single-line rows.
     *
     * @param  array{header: bool, lines: array<int, list<float>>}  $row
     * @param  array<int, float>  $widths
     */
    private function fittedHeight(array $row, array $widths): ?float
    {
        $lines = 1;

        foreach ($row['lines'] as $column => $lengths) {
            // A width unit is the width of "0"; average Calibri text is a little narrower.
            $characters = max(1, ($widths[$column] ?? 8.43) * 1.05 - 1);
            $lines = max($lines, array_sum(array_map(fn (float $length) => max(1, (int) ceil($length / $characters)), $lengths)));
        }

        return match (true) {
            // Headers get a little air above and below the labels.
            $row['header'] => min(self::MAX_HEIGHT, $lines * self::LINE_HEIGHT + 8),
            // Data rows: the same padding above and below, one line or many.
            $row['lines'] !== [] => min(self::MAX_HEIGHT, $lines * self::LINE_HEIGHT + self::ROW_PADDING),
            default => null,
        };
    }

    private function cellXml(string $ref, mixed $value, int $style): string
    {
        $attributes = 'r="'.$ref.'"'.($style > 0 ? ' s="'.$style.'"' : '');

        if ($value === null || $value === '') {
            return '<c '.$attributes.'/>';
        }

        if ($value instanceof DateTimeInterface) {
            return '<c '.$attributes.'><v>'.self::number(self::serial($value)).'</v></c>';
        }

        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value)
                ? '<c '.$attributes.'><v>'.self::number($value).'</v></c>'
                : '<c '.$attributes.'/>';
        }

        if (is_bool($value)) {
            $value = $value ? 'Ya' : 'Tidak';
        }

        $text = mb_substr(self::clean((string) $value), 0, self::MAX_CELL_TEXT);

        return '<c '.$attributes.' t="inlineStr"><is><t xml:space="preserve">'.XlsxWorkbook::escape($text).'</t></is></c>';
    }

    /** Remove characters XML 1.0 cannot carry (control characters, broken UTF-8). */
    private static function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
    }

    private static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        // Full precision without exponent noise for ordinary values.
        $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    private static function quote(string $sheetName): string
    {
        return "'".str_replace("'", "''", $sheetName)."'";
    }

    private function measure(mixed $value, string $style): float
    {
        return match (true) {
            $value === null || $value === '' => 0,
            $style === 'date' => 11,
            $style === 'datetime' => 17,
            $style === 'time' => 6,
            $value instanceof DateTimeInterface => 17,
            is_int($value) || is_float($value) => strlen(self::number($value)),
            // The longest line of the text; header/title styles are wider.
            default => max(array_map('mb_strlen', explode("\n", (string) $value))) * ($style === 'header' ? 1.1 : 1),
        };
    }
}
