<?php

declare(strict_types=1);

namespace App\Domain\Export\Excel;

use Closure;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** The export's rows, streamed chunk by chunk from the export type. */
final class DataSheet implements FromGenerator, WithCustomValueBinder, WithHeadings, WithTitle
{
    use TextValueBinder;

    /**
     * @param  list<string>  $headings
     * @param  Closure(): Generator<int, list<string|int|float|null>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly Closure $rows,
    ) {}

    public function generator(): Generator
    {
        yield from ($this->rows)();
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }
}
