<?php

declare(strict_types=1);

namespace App\Domain\Export\Excel;

use Closure;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * "About this file": what the export is, its filters, when (WAT) and by whom it was generated, the
 * rule versions, rounding mode, aggregation method, data sources, whether personal details are in
 * it, and the line on published and provisional figures. Built after the data sheet, so the row
 * count is final.
 */
final class AboutSheet implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
{
    use TextValueBinder;

    /** @param  Closure(): list<array{0: string, 1: string|int}>  $rows */
    public function __construct(
        private readonly string $title,
        private readonly Closure $rows,
    ) {}

    /** @return list<array{0: string, 1: string|int}> */
    public function array(): array
    {
        return ($this->rows)();
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
                $sheet->getStyle('A1:A'.max(1, $sheet->getHighestDataRow()))->getFont()->setBold(true);
                $sheet->getColumnDimension('A')->setWidth(30);
                $sheet->getColumnDimension('B')->setWidth(90);
                $sheet->getStyle('B1:B'.max(1, $sheet->getHighestDataRow()))->getAlignment()->setWrapText(true);
            },
        ];
    }
}
