<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Enums\ExportFormat;
use Closure;

/**
 * An export that is a finished document, not a table of rows (Phase 6 step 5, D-101: the PDF round
 * report). It comes in one format, is written by its own job (GenerateRoundReport on the exports
 * queue) through the same plumbing (queued, progress, signed download, audited), and names its own
 * file. columns() and chunks() are empty: there are no rows to stream.
 */
interface DocumentExport extends ExportType
{
    public function format(): ExportFormat;

    /**
     * The file's name without the extension: "round-report-2026-Q2", "-DRAFT" for an open round.
     *
     * @param  array<string, mixed>  $filter
     */
    public function fileName(array $filter): string;

    /**
     * Writes the document to $path (an absolute path on the exports disk) and returns its page
     * count. $progress takes 0–99.
     *
     * @param  array<string, mixed>  $filter
     * @param  Closure(int): void  $progress
     */
    public function write(array $filter, string $path, Closure $progress): int;
}
