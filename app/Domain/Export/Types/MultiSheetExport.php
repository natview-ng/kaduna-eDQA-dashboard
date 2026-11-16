<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Support\Sheet;

/**
 * An export made of several sheets (Phase 6 step 2: the backfill report). Excel only: a CSV has
 * one table. columns() and chunks() describe its first sheet; the writer uses sheets().
 */
interface MultiSheetExport extends ExportType
{
    /**
     * @param  array<string, mixed>  $filter
     * @return list<Sheet>
     */
    public function sheets(array $filter, bool $private = false): array;
}
