<?php

namespace Tests\Concerns;

use DOMDocument;
use PHPUnit\Framework\Assert;
use SimpleXMLElement;

/**
 * Reads .xlsx downloads back in tests, without ext-zip: the archive is
 * parsed from its central directory and each part is inflated.
 */
trait ReadsXlsx
{
    /**
     * Every part of the archive by name. Fails when the bytes are not a
     * well-formed ZIP of well-formed XML parts.
     *
     * @return array<string, string>
     */
    protected function xlsxParts(string $bytes): array
    {
        Assert::assertStringStartsWith("PK\x03\x04", $bytes, 'Not a ZIP archive.');

        $end = strrpos($bytes, "PK\x05\x06");
        Assert::assertNotFalse($end, 'ZIP end of central directory missing.');
        $eocd = unpack('Vsig/vdisk/vcddisk/ventries/vtotal/Vsize/Voffset/vcomment', substr($bytes, $end, 22));

        $parts = [];
        $position = $eocd['offset'];

        for ($i = 0; $i < $eocd['total']; $i++) {
            $entry = unpack('Vsig/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Voffset', substr($bytes, $position, 46));
            Assert::assertSame(0x02014B50, $entry['sig']);
            $name = substr($bytes, $position + 46, $entry['nlen']);
            $position += 46 + $entry['nlen'] + $entry['elen'] + $entry['clen'];

            $local = unpack('Vsig/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen', substr($bytes, $entry['offset'], 30));
            Assert::assertSame(0x04034B50, $local['sig']);
            $data = substr($bytes, $entry['offset'] + 30 + $local['nlen'] + $local['elen'], $entry['csize']);
            $contents = $entry['method'] === 8 ? gzinflate($data) : $data;

            Assert::assertNotFalse($contents, "Part {$name} cannot be inflated.");
            Assert::assertSame($entry['crc'], crc32($contents), "CRC mismatch in {$name}.");
            Assert::assertSame($entry['size'], strlen($contents));

            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                Assert::assertTrue((new DOMDocument)->loadXML($contents), "{$name} is not well-formed XML.");
            }

            $parts[$name] = $contents;
        }

        return $parts;
    }

    /**
     * Sheet name => rows (row number => [column letter => cell]), where a
     * cell is ['value' => string|float|null, 'style' => int, 'type' => 'text'|'number'|'empty'].
     *
     * @return array<string, array<int, array<string, array{value: string|float|null, style: int, type: string}>>>
     */
    protected function xlsxSheets(string $bytes): array
    {
        $parts = $this->xlsxParts($bytes);
        $workbook = new SimpleXMLElement($parts['xl/workbook.xml']);
        $sheets = [];

        foreach ($workbook->sheets->sheet as $sheet) {
            $sheets[(string) $sheet['name']] = $this->xlsxRows($parts['xl/worksheets/sheet'.(count($sheets) + 1).'.xml']);
        }

        return $sheets;
    }

    /**
     * The worksheet XML of the named sheet, for structure assertions.
     */
    protected function xlsxSheetXml(string $bytes, string $name): SimpleXMLElement
    {
        $parts = $this->xlsxParts($bytes);
        $names = [];
        foreach ((new SimpleXMLElement($parts['xl/workbook.xml']))->sheets->sheet as $sheet) {
            $names[] = (string) $sheet['name'];
        }
        $index = array_search($name, $names, true);
        Assert::assertNotFalse($index, "No sheet named {$name}.");

        return new SimpleXMLElement($parts['xl/worksheets/sheet'.($index + 1).'.xml']);
    }

    /**
     * Plain values of one row, in column order.
     *
     * @param  array<string, array{value: string|float|null}>  $row
     * @return list<string|float|null>
     */
    protected function xlsxValues(array $row): array
    {
        return array_values(array_map(fn (array $cell) => $cell['value'], $row));
    }

    /**
     * @return array<int, array<string, array{value: string|float|null, style: int, type: string}>>
     */
    private function xlsxRows(string $xml): array
    {
        $rows = [];

        foreach ((new SimpleXMLElement($xml))->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                preg_match('/^([A-Z]+)\d+$/', (string) $cell['r'], $ref);
                $type = (string) $cell['t'];

                $cells[$ref[1]] = match (true) {
                    $type === 'inlineStr' => ['value' => (string) $cell->is->t, 'style' => (int) $cell['s'], 'type' => 'text'],
                    isset($cell->v) => ['value' => (float) (string) $cell->v, 'style' => (int) $cell['s'], 'type' => 'number'],
                    default => ['value' => null, 'style' => (int) $cell['s'], 'type' => 'empty'],
                };
            }

            $rows[(int) $row['r']] = $cells;
        }

        return $rows;
    }
}
