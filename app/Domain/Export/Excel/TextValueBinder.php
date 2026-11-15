<?php

declare(strict_types=1);

namespace App\Domain\Export\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Text is written as text, never read as a formula (a facility name starting with "=" stays a
 * name: no formula injection); numbers stay numbers.
 */
trait TextValueBinder
{
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder)->bindValue($cell, $value);
    }
}
