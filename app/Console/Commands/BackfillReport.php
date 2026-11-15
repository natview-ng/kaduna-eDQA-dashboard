<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Export\Support\ExportDescription;
use App\Domain\Export\Support\ExportWriter;
use App\Domain\Export\Types\BackfillReportExport;
use App\Domain\Ingestion\Queries\BackfillReportQuery;
use App\Domain\Ingestion\Reports\BackfillReportWriter;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The backfill report (ARCHITECTURE.md §5.6): per round, what was pulled, accepted, flagged and
 * quarantined, then the quarantined ones by rule and year, by rule and form version, and the most
 * frequent details of each rule. Written to storage/app/exports as Markdown, CSV, or (Phase 6
 * step 2) Excel: Summary, By rule × year, By rule × form version, Examples, Unmatched facilities
 * and About this file, the same workbook as the Data sources page's download.
 */
final class BackfillReport extends Command
{
    protected $signature = 'edqa:backfill-report {--format=md : md, csv or xlsx} {--source= : One source only (xlsx), e.g. kobo}';

    protected $description = 'Write the backfill report to storage/app/exports';

    public function handle(BackfillReportQuery $query, BackfillReportWriter $writer, BackfillReportExport $workbook, ExportWriter $excel, ExportDescription $description, SourceRegistry $sources): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, [...BackfillReportWriter::FORMATS, 'xlsx'], true)) {
            $this->error((string) __('backfill.report.bad_format', ['formats' => implode(', ', [...BackfillReportWriter::FORMATS, 'xlsx'])]));

            return self::FAILURE;
        }

        if ($format !== 'xlsx') {
            $path = $writer->write($query->build(), $format);
        } else {
            $source = is_string($this->option('source')) && $this->option('source') !== '' ? (string) $this->option('source') : null;
            if ($source !== null && ! in_array($source, $sources->keys(), true)) {
                $this->error((string) __('sources.check.unknown', ['key' => $source, 'keys' => implode(', ', $sources->keys())]));

                return self::FAILURE;
            }
            $filter = $source === null ? [] : ['source' => $source];
            $path = 'backfill-report-'.now()->format('Ymd-His').'.xlsx';
            $excel->writeSheets(
                $workbook->sheets($filter),
                $path,
                fn (int $rows): array => $description->rows($workbook, $filter, (string) __('exports.types.audit_log.console'), $rows, false),
            );
        }
        $this->info((string) __('backfill.report.written', ['path' => Storage::disk('exports')->path($path)]));

        return self::SUCCESS;
    }
}
