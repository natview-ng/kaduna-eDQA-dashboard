<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Writes a minimal .xlsx (Office Open XML) for the master-list import tests: one worksheet per
 * sheet name, every cell an inline string, the first row the header. Enough for
 * MasterListReader, which also reads shared strings (what Excel and openpyxl write).
 */
final class XlsxFixture
{
    /**
     * @param  array<string, list<list<string|int|float|null>>>  $sheets  sheet name => rows (header first)
     */
    public static function write(string $path, array $sheets): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write {$path}");
        }

        $names = array_keys($sheets);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .implode('', array_map(fn (int $i): string => '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($names)))
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .implode('', array_map(fn (int $i, string $name): string => '<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>', array_keys($names), $names))
            .'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', array_map(fn (int $i): string => '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>', array_keys($names)))
            .'</Relationships>');

        foreach (array_values($sheets) as $i => $rows) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($rows as $r => $row) {
                $xml .= '<row r="'.($r + 1).'">';
                foreach (array_values($row) as $c => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $ref = self::column($c).($r + 1);
                    $xml .= is_int($value) || is_float($value)
                        ? '<c r="'.$ref.'"><v>'.$value.'</v></c>'
                        : '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
                }
                $xml .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $xml.'</sheetData></worksheet>');
        }
        $zip->close();

        return $path;
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }
}
