<?php

declare(strict_types=1);

namespace App\Domain\Export\Excel;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** An Excel export: the data, then "About this export". */
final class Workbook implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly DataSheet $data,
        private readonly AboutSheet $about,
    ) {}

    /** @return list<object> */
    public function sheets(): array
    {
        return [$this->data, $this->about];
    }
}
