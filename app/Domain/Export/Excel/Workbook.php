<?php

declare(strict_types=1);

namespace App\Domain\Export\Excel;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** An Excel export: its data sheet or sheets, then "About this file". */
final class Workbook implements Export, WithMultipleSheets
{
    /** @param  list<DataSheet>  $data */
    public function __construct(
        private readonly array $data,
        private readonly AboutSheet $about,
    ) {}

    /** @return list<object> */
    public function sheets(): array
    {
        return [...$this->data, $this->about];
    }
}
