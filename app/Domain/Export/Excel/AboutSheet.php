<?php

declare(strict_types=1);

namespace App\Domain\Export\Excel;

use Closure;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * "About this export": what it is, the filter, who asked and when, the row count and whether
 * assessor and source details are in it. Built after the data sheet, so the count is final.
 */
final class AboutSheet implements FromArray, WithCustomValueBinder, WithTitle
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
}
