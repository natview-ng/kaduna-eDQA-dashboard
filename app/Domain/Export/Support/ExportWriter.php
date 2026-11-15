<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

use App\Domain\Export\Enums\ExportFormat;
use App\Domain\Export\Excel\AboutSheet;
use App\Domain\Export\Excel\DataSheet;
use App\Domain\Export\Excel\Workbook;
use App\Domain\Export\Types\ExportType;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Support\Export\SpreadsheetCell;
use Closure;
use Generator;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel;
use RuntimeException;

/**
 * Writes an export to the private exports disk (SECURITY.md §7), chunk by chunk, telling the
 * caller how many rows are written after each chunk (the progress). CSV: UTF-8 with a byte-order
 * mark, so spreadsheets read names like Jema'a right, and any text a spreadsheet would read as a
 * formula prefixed with an apostrophe. Excel: the data sheet, then "About this export" (the
 * metadata, including whether assessor and source details are in it), every text cell written
 * as text.
 */
final class ExportWriter
{
    public const DISK = 'exports';

    public function __construct(
        private readonly Excel $excel,
    ) {}

    /**
     * @param  Closure(int): void  $progress  rows written so far, after each chunk
     * @param  Closure(int): list<array{0: string, 1: string|int}>  $about  the metadata, given the final row count
     * @return int rows written
     */
    public function write(ExportType $type, ReportFilter $filter, bool $private, ExportFormat $format, string $path, int $chunk, Closure $progress, Closure $about): int
    {
        $written = 0;
        $rows = function () use ($type, $filter, $private, $chunk, $progress, &$written): Generator {
            foreach ($type->chunks($filter, $private, $chunk) as $rows) {
                foreach ($rows as $row) {
                    yield $row;
                }
                $written += count($rows);
                $progress($written);
            }
        };

        if ($format === ExportFormat::Csv) {
            $this->csv($type->headings($private), $rows, $path);
        } else {
            $this->excel->store(new Workbook(
                new DataSheet((string) __('exports.sheets.data'), $type->headings($private), $rows),
                new AboutSheet((string) __('exports.sheets.about'), function () use ($about, &$written): array {
                    return $about($written);
                }),
            ), $path, self::DISK, Excel::XLSX);
        }

        return $written;
    }

    /**
     * @param  list<string>  $headings
     * @param  Closure(): Generator<int, list<string|int|float|null>>  $rows
     */
    private function csv(array $headings, Closure $rows, string $path): void
    {
        $handle = tmpfile();
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary file for the export.');
        }
        try {
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, $headings, escape: '');
            foreach ($rows() as $row) {
                fputcsv($handle, array_map(fn (string|int|float|null $cell): string|int|float|null => is_string($cell) ? SpreadsheetCell::safe($cell) : $cell, $row), escape: '');
            }
            rewind($handle);
            Storage::disk(self::DISK)->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }
    }
}
