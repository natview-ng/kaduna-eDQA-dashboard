<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Support\Column;
use Illuminate\Validation\ValidationException;

/**
 * One kind of export (D-96, D-97). Each mirrors a page and reads its numbers
 * from that page's own aggregator or query, never from SQL of its own. The filter is the page's:
 * the dashboard's (year, quarter, month, LGA, level, owner, for the admin audience) for the report
 * types, the page's own for data issues, pull history and the audit log.
 */
interface ExportType
{
    /** The key asked for in POST /admin/api/exports: "facility_scores". */
    public function key(): string;

    /** Whether "Include assessor and source details" applies to this type. */
    public function offersPrivate(): bool;

    /**
     * The filter as asked for, checked and in its stored form.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function filter(array $input): array;

    /**
     * @param  array<string, mixed>  $filter
     * @return list<Column>
     */
    public function columns(array $filter, bool $private): array;

    /** @param  array<string, mixed>  $filter */
    public function count(array $filter): int;

    /**
     * The rows in chunks of about $size, one cell per column.
     *
     * @param  array<string, mixed>  $filter
     * @return iterable<list<list<string|int|float|null>>>
     */
    public function chunks(array $filter, bool $private, int $size): iterable;

    /**
     * Lines for "About this file" that belong to this type: its filter in words, the rule
     * versions read.
     *
     * @param  array<string, mixed>  $filter
     * @return list<array{0: string, 1: string}>
     */
    public function describe(array $filter): array;
}
