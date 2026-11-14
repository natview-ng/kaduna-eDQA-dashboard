<?php

declare(strict_types=1);

namespace App\Domain\Facility\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads named worksheets of an .xlsx workbook into rows of text, with each row's line number as
 * the spreadsheet shows it (D-91). Just enough of Office Open XML for the reconciliation workbook
 * (facility_reconcile.py writes it with openpyxl; the client edits it in Excel): shared strings,
 * inline strings, numbers and booleans. Formulas give their cached value. No import library is
 * used (CLAUDE.md: none is wanted), and this reads files on the server's disk only: there is no
 * upload route.
 */
final class XlsxSheets
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var array<string, string> sheet name => path in the zip */
    private array $paths = [];

    /** @var list<string> */
    private array $shared = [];

    private function __construct(private readonly ZipArchive $zip) {}

    public static function open(string $path): self
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Not a readable .xlsx: {$path}");
        }
        $reader = new self($zip);
        $reader->index();

        return $reader;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->paths);
    }

    /**
     * Every non-empty row of a sheet: its line number and its cells by column (0 = A), trimmed.
     *
     * @return list<array{line: int, cells: array<int, string>}>
     */
    public function rows(string $sheet): array
    {
        $path = $this->paths[$sheet] ?? throw new RuntimeException("No sheet {$sheet}");
        $xml = $this->xml($path);
        $rows = [];
        foreach ($xml->children(self::MAIN)->sheetData->children(self::MAIN)->row as $row) {
            $line = (int) $row->attributes()['r'];
            $cells = [];
            foreach ($row->children(self::MAIN)->c as $cell) {
                $attributes = $cell->attributes();
                $column = self::column((string) $attributes['r']);
                $value = trim($this->value($cell, (string) $attributes['t']));
                if ($value !== '') {
                    $cells[$column] = $value;
                }
            }
            if ($cells !== []) {
                $rows[] = ['line' => $line, 'cells' => $cells];
            }
        }

        return $rows;
    }

    private function index(): void
    {
        $targets = [];
        foreach ($this->xml('xl/_rels/workbook.xml.rels')->children() as $relation) {
            $attributes = $relation->attributes();
            $target = ltrim((string) $attributes['Target'], '/');
            $targets[(string) $attributes['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }
        foreach ($this->xml('xl/workbook.xml')->children(self::MAIN)->sheets->children(self::MAIN)->sheet as $sheet) {
            $id = (string) $sheet->attributes(self::REL)['id'];
            if (isset($targets[$id])) {
                $this->paths[(string) $sheet->attributes()['name']] = $targets[$id];
            }
        }
        if ($this->zip->locateName('xl/sharedStrings.xml') !== false) {
            foreach ($this->xml('xl/sharedStrings.xml')->children(self::MAIN)->si as $item) {
                $this->shared[] = self::text($item);
            }
        }
    }

    private function value(SimpleXMLElement $cell, string $type): string
    {
        $children = $cell->children(self::MAIN);

        return match ($type) {
            's' => $this->shared[(int) $children->v] ?? '',
            'inlineStr' => self::text($children->is),
            'b' => ((string) $children->v) === '1' ? 'TRUE' : 'FALSE',
            default => (string) $children->v,
        };
    }

    /** A string item: its <t>, or the <t>s of its rich-text runs. */
    private static function text(SimpleXMLElement $item): string
    {
        $children = $item->children(self::MAIN);
        if (isset($children->t)) {
            return (string) $children->t;
        }
        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::MAIN)->t;
        }

        return $text;
    }

    private function xml(string $path): SimpleXMLElement
    {
        $content = $this->zip->getFromName($path);
        $xml = is_string($content) ? simplexml_load_string($content, options: LIBXML_NONET) : false;
        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException("Unreadable part {$path}");
        }

        return $xml;
    }

    /** "C12" → 2. */
    private static function column(string $reference): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($reference)) ?? '';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
