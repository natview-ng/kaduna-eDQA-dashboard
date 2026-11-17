<?php

declare(strict_types=1);

namespace App\Domain\Export\Enums;

use App\Support\Enums\HasLabel;

/**
 * The file an export is written as: CSV, or Excel with an "About" sheet; PDF for
 * the round report alone (D-101).
 */
enum ExportFormat: string
{
    use HasLabel;

    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    public function mime(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }

    protected static function labelGroup(): string
    {
        return 'export_format';
    }
}
