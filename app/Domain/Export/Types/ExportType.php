<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Reporting\DTOs\ReportFilter;

/**
 * One kind of export (Phase 6 step 0, D-96): what it holds for a dashboard filter, as rows of
 * plain cells. Figures come from Reporting (every aggregate is computed there, hard rule 5); the
 * private columns (assessor, device, visit GPS, source details and attachment links) are added
 * only when the requester chose "Include assessor and source details", and only by a type that
 * offers them.
 */
interface ExportType
{
    /** The key asked for in POST /admin/api/exports: "assessments". */
    public function key(): string;

    /** Whether the "Include assessor and source details" option applies to this type. */
    public function offersPrivate(): bool;

    /** @return list<string> the column headings, in order */
    public function headings(bool $private): array;

    public function count(ReportFilter $filter): int;

    /**
     * The rows in chunks of about $size, each row one cell per heading.
     *
     * @return iterable<list<list<string|int|float|null>>>
     */
    public function chunks(ReportFilter $filter, bool $private, int $size): iterable;
}
