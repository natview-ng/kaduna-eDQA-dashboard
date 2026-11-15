<?php

declare(strict_types=1);

namespace App\Domain\Export\Excel;

use App\Domain\Export\Support\Column;
use Closure;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * The export's rows, streamed chunk by chunk from the export type, then formatted (Phase 6 step 1):
 * a bold header row frozen at the top with an autofilter, each column's number format (scores at
 * full precision and as displayed, counts, dates) and a width to suit it.
 */
final class DataSheet implements FromGenerator, WithCustomValueBinder, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    use TextValueBinder;

    /**
     * @param  list<Column>  $columns
     * @param  Closure(): Generator<int, list<string|int|float|null>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $columns,
        private readonly Closure $rows,
    ) {}

    public function generator(): Generator
    {
        yield from ($this->rows)();
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn (Column $column): string => $column->heading, $this->columns);
    }

    public function title(): string
    {
        return $this->title;
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->getSheet()->getDelegate();
                $last = Coordinate::stringFromColumnIndex(max(1, count($this->columns)));
                // Strict null comparison writes a zero as 0, never as an empty cell; an empty export
                // keeps its header row alone, with nothing formatted below it.
                $rows = $sheet->getHighestDataRow();

                $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$last}{$rows}");
                foreach ($this->columns as $i => $column) {
                    $letter = Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->getColumnDimension($letter)->setWidth($column->width());
                    $format = $column->kind->excelFormat();
                    if ($format !== null && $rows >= 2) {
                        $sheet->getStyle("{$letter}2:{$letter}{$rows}")->getNumberFormat()->setFormatCode($format);
                    }
                }
            },
        ];
    }
}
