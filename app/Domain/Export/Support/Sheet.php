<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

/**
 * One sheet of a multi-sheet export (the backfill report, the workplan): its title, columns and
 * rows, and, for a sheet meant to be printed and filled in (the workplan's Plan sheet), a dropdown
 * list per column (index => the choices) and an A4 landscape page with the header row repeated.
 */
final readonly class Sheet
{
    /**
     * @param  list<Column>  $columns
     * @param  list<list<string|int|float|null>>  $rows
     * @param  array<int, list<string>>  $dropdowns  column index (0-based) => its choices
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
        public array $dropdowns = [],
        public bool $printLandscape = false,
    ) {}
}
