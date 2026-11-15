<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

/** One sheet of a multi-sheet export (the backfill report): its title, columns and rows. */
final readonly class Sheet
{
    /**
     * @param  list<Column>  $columns
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
    ) {}
}
