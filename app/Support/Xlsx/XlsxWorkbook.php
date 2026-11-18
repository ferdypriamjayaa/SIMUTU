<?php

namespace App\Support\Xlsx;

/**
 * Writes an Office Open XML workbook (.xlsx) with the house styles used by
 * SIMUTU reports. Sheets are filled through XlsxSheet and the file is
 * written once with save().
 */
class XlsxWorkbook
{
    /**
     * Cell style name => index in styles.xml cellXfs (see styles()).
     */
    public const STYLES = [
        'default' => 0,
        'header' => 1,
        'text' => 2,
        'number' => 3,
        'date' => 4,
        'datetime' => 5,
        'time' => 6,
        'title' => 7,
        'subtitle' => 8,
        'label' => 9,
        'link' => 10,
        'note' => 11,
        // The same data styles on a shaded band, for alternating rows.
        'text_alt' => 12,
        'number_alt' => 13,
        'date_alt' => 14,
        'datetime_alt' => 15,
        'time_alt' => 16,
        'link_alt' => 17,
    ];

    /** Styles that wrap long text, so their rows grow to fit it. */
    public const WRAPPING = ['header', 'text', 'text_alt', 'label', 'link', 'link_alt'];

    /** Names Excel reserves for itself. */
    private const RESERVED_SHEET_NAMES = ['history'];

    /** @var list<XlsxSheet> */
    private array $sheets = [];

    /** @var list<string> lower-cased names in use */
    private array $names = [];

    public function __construct(private readonly string $title = 'Laporan') {}

    /**
     * Add a sheet. The name is made valid for Excel: no []:*?/\ characters,
     * at most 31 characters, and unique (a " (2)" suffix when taken).
     */
    public function addSheet(string $name): XlsxSheet
    {
        $sheet = new XlsxSheet($this->uniqueName($name));
        $this->sheets[] = $sheet;

        return $sheet;
    }

    public static function sheetName(string $name): string
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]+/u', ' ', $name) ?? '');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', " '");

        return mb_substr($name !== '' ? $name : 'Sheet', 0, 31);
    }

    public function save(string $path): void
    {
        if ($this->sheets === []) {
            $this->addSheet('Sheet1');
        }

        $zip = new ZipWriter($path);
        $zip->add('[Content_Types].xml', $this->contentTypes());
        $zip->add('_rels/.rels', $this->rootRelationships());
        $zip->add('docProps/core.xml', $this->coreProperties());
        $zip->add('docProps/app.xml', $this->appProperties());
        $zip->add('xl/workbook.xml', $this->workbook());
        $zip->add('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->add('xl/styles.xml', $this->styles());

        foreach ($this->sheets as $index => $sheet) {
            $zip->add('xl/worksheets/sheet'.($index + 1).'.xml', $sheet->toXml());
        }

        $zip->close();
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function uniqueName(string $name): string
    {
        $base = self::sheetName($name);
        $candidate = $base;

        for ($n = 2; in_array(mb_strtolower($candidate), [...$this->names, ...self::RESERVED_SHEET_NAMES], true); $n++) {
            $suffix = " ({$n})";
            $candidate = rtrim(mb_substr($base, 0, 31 - mb_strlen($suffix))).$suffix;
        }

        $this->names[] = mb_strtolower($candidate);

        return $candidate;
    }

    private function contentTypes(): string
    {
        $sheets = '';

        foreach (array_keys($this->sheets) as $index) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .$sheets
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function coreProperties(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.self::escape($this->title).'</dc:title>'
            .'<dc:creator>SIMUTU</dc:creator>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>SIMUTU</Application></Properties>';
    }

    private function workbook(): string
    {
        $sheets = '';
        $names = '';
        $printTitles = '';

        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="'.self::escape($sheet->name).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';

            if ($range = $sheet->autoFilterRange()) {
                $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$index.'" hidden="1">'
                    .self::escape($sheet->reference($range)).'</definedName>';
            }

            // Title and header rows repeat on every printed page.
            if ($rows = $sheet->printTitlesReference()) {
                $printTitles .= '<definedName name="_xlnm.Print_Titles" localSheetId="'.$index.'">'.self::escape($rows).'</definedName>';
            }
        }

        $names .= $printTitles;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView activeTab="0"/></bookViews>'
            .'<sheets>'.$sheets.'</sheets>'
            .($names !== '' ? '<definedNames>'.$names.'</definedNames>' : '')
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        $relationships = '';

        foreach (array_keys($this->sheets) as $index) {
            $relationships .= '<Relationship Id="rId'.($index + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($index + 1).'.xml"/>';
        }

        $relationships .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>';
    }

    /**
     * Minimal house style: navy header with white bold centred text, thin
     * light borders around data cells, a pale band on every other data row,
     * and DD-MM-YYYY dates. Every heading and data cell is centred both ways;
     * text wraps instead of spilling into the next column.
     */
    private function styles(): string
    {
        $border = 'borderId="1" applyBorder="1"';
        $wrap = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
        $centre = '<alignment horizontal="center" vertical="center"/>';
        $band = 'fillId="4" applyFill="1"';

        // One data style, plain (fill 0) or banded.
        $data = fn (string $fill) => [
            // text
            '<xf numFmtId="49" fontId="0" '.$fill.' '.$border.' applyNumberFormat="1" applyAlignment="1" xfId="0">'.$wrap.'</xf>',
            // number
            '<xf numFmtId="0" fontId="0" '.$fill.' '.$border.' applyAlignment="1" xfId="0">'.$centre.'</xf>',
            // date, date & time, time
            '<xf numFmtId="164" fontId="0" '.$fill.' '.$border.' applyNumberFormat="1" applyAlignment="1" xfId="0">'.$centre.'</xf>',
            '<xf numFmtId="165" fontId="0" '.$fill.' '.$border.' applyNumberFormat="1" applyAlignment="1" xfId="0">'.$centre.'</xf>',
            '<xf numFmtId="166" fontId="0" '.$fill.' '.$border.' applyNumberFormat="1" applyAlignment="1" xfId="0">'.$centre.'</xf>',
            // link
            '<xf numFmtId="0" fontId="5" '.$fill.' '.$border.' applyFont="1" applyAlignment="1" xfId="0">'.$wrap.'</xf>',
        ];
        [$text, $number, $date, $datetime, $time, $link] = $data('fillId="0"');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3">'
            .'<numFmt numFmtId="164" formatCode="dd\-mm\-yyyy"/>'
            .'<numFmt numFmtId="165" formatCode="dd\-mm\-yyyy\ hh:mm"/>'
            .'<numFmt numFmtId="166" formatCode="hh:mm"/>'
            .'</numFmts>'
            .'<fonts count="6">'
            .'<font><sz val="11"/><color rgb="FF172A46"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="14"/><color rgb="FF172A46"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF172A46"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><u/><sz val="11"/><color rgb="FF0563C1"/><name val="Calibri"/><family val="2"/></font>'
            .'</fonts>'
            .'<fills count="5">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF172A46"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF6"/><bgColor indexed="64"/></patternFill></fill>'
            // band of alternating rows
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF4F7FB"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"><color rgb="FFC2D1E6"/></left><right style="thin"><color rgb="FFC2D1E6"/></right>'
            .'<top style="thin"><color rgb="FFC2D1E6"/></top><bottom style="thin"><color rgb="FFC2D1E6"/></bottom><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="18">'
            // 0 default
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 header: navy, white bold, centred, wrapped
            .'<xf numFmtId="0" fontId="1" fillId="2" '.$border.' applyFont="1" applyFill="1" applyAlignment="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 2 text, 3 number, 4 date, 5 date & time, 6 time
            .$text.$number.$date.$datetime.$time
            // 7 title, 8 subtitle
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" applyFont="1" applyAlignment="1" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" applyFont="1" applyAlignment="1" xfId="0"><alignment vertical="center"/></xf>'
            // 9 label (bold, light fill)
            .'<xf numFmtId="0" fontId="4" fillId="3" '.$border.' applyFont="1" applyFill="1" applyAlignment="1" xfId="0">'.$wrap.'</xf>'
            // 10 link
            .$link
            // 11 note
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" applyFont="1" applyAlignment="1" xfId="0"><alignment vertical="top"/></xf>'
            // 12-17 text, number, date, date & time, time, link on the band
            .implode('', $data($band))
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
