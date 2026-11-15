<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

use App\Domain\Export\Enums\ColumnKind;
use App\Domain\Export\Enums\ExportFormat;
use App\Domain\Export\Excel\AboutSheet;
use App\Domain\Export\Excel\DataSheet;
use App\Domain\Export\Excel\Workbook;
use App\Domain\Export\Types\ExportType;
use App\Support\Export\SpreadsheetCell;
use Closure;
use Generator;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel;
use RuntimeException;

/**
 * Writes an export to the private exports disk (SECURITY.md §7), chunk by chunk, telling the
 * caller how many rows are written after each chunk (the progress).
 *
 * CSV (RFC 4180): UTF-8 with a byte-order mark, so spreadsheets read names like Jema'a and
 * accented or Hausa names right; every field quoted when it holds a comma, quote or line break,
 * quotes doubled, CRLF line ends; text a spreadsheet would read as a formula (= + - @ at the
 * start) prefixed with an apostrophe. Excel: the formatted data sheet, then "About this file",
 * every text cell written as text.
 */
final class ExportWriter
{
    public const DISK = 'exports';

    public function __construct(
        private readonly Excel $excel,
    ) {}

    /**
     * @param  array<string, mixed>  $filter
     * @param  Closure(int): void  $progress  rows written so far, after each chunk
     * @param  Closure(int): list<array{0: string, 1: string|int}>  $about  "About this file", given the final row count
     * @return int rows written
     */
    public function write(ExportType $type, array $filter, bool $private, ExportFormat $format, string $path, int $chunk, Closure $progress, Closure $about): int
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
        $columns = $type->columns($filter, $private);

        if ($format === ExportFormat::Csv) {
            // A displayed score keeps its one decimal place in text, as on the dashboard ("85.0").
            $displayed = array_keys(array_filter($columns, fn (Column $column): bool => $column->kind === ColumnKind::Displayed));
            $csvRows = function () use ($rows, $displayed): Generator {
                foreach ($rows() as $row) {
                    foreach ($displayed as $i) {
                        if (is_float($row[$i] ?? null) || is_int($row[$i] ?? null)) {
                            $row[$i] = number_format((float) $row[$i], 1, '.', '');
                        }
                    }
                    yield $row;
                }
            };
            $this->csv(array_map(fn (Column $column): string => $column->heading, $columns), $csvRows, $path);
        } else {
            $this->excel->store(new Workbook(
                new DataSheet((string) __('exports.sheets.data'), $columns, $rows),
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
            self::line($handle, $headings);
            foreach ($rows() as $row) {
                self::line($handle, $row);
            }
            rewind($handle);
            Storage::disk(self::DISK)->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * One RFC 4180 record: quoted where needed, quotes doubled, no backslash escaping, CRLF.
     *
     * @param  resource  $handle
     * @param  list<string|int|float|null>  $cells
     */
    private static function line($handle, array $cells): void
    {
        fputcsv($handle, array_map(fn (string|int|float|null $cell): string|int|float|null => is_string($cell) ? SpreadsheetCell::safe($cell) : $cell, $cells), ',', '"', '', "\r\n");
    }
}
