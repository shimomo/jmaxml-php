<?php

declare(strict_types=1);

namespace JmaXml\Codegen\Tests\Xlsx;

use JmaXml\Codegen\Xlsx\XlsxReader;
use JmaXml\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * @author shimomo
 */
final class XlsxReaderTest extends TestCase
{
    #[Test]
    public function stripsPhoneticRunsFromSharedStrings(): void
    {
        $path = $this->createWorkbook();

        try {
            $reader = XlsxReader::open($path);

            $this->assertSame(['Sheet1'], $reader->sheetNames());
            $this->assertSame([['A' => 'コード表名', 'B' => '42'], ['A' => 'inline']], $reader->rows('Sheet1'));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function readsOfficialDictionarySheets(): void
    {
        $this->assertSame(
            ['jmx', 'jmx_ib', 'jmx_eb', 'jmx_mete', 'jmx_seis', 'jmx_volc', 'jmx_add', '#ns', '#注釈'],
            XlsxReader::open(Fixtures::dictionaryPath())->sheetNames(),
        );
    }

    private function createWorkbook(): string
    {
        $path = sys_get_temp_dir() . '/jmaxml-xlsx-' . bin2hex(random_bytes(4)) . '.xlsx';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
              <sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>
            </workbook>
            XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
              <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
            </Relationships>
            XML);
        $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="2" uniqueCount="2">
              <si><r><t>コード表</t></r><r><t>名</t></r><rPh sb="0" eb="3"><t>ヒョウメイ</t></rPh></si>
              <si><t>Code</t></si>
            </sst>
            XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <sheetData>
                <row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1"><v>42</v></c><c r="C1"/></row>
                <row r="2"><c r="A2" t="inlineStr"><is><t>inline</t></is></c></row>
              </sheetData>
            </worksheet>
            XML);
        $zip->close();

        return $path;
    }
}
