<?php

declare(strict_types=1);

namespace App\Domain\Export\Actions;

use App\Domain\Export\Enums\ExportFormat;
use App\Domain\Export\Exceptions\ExportRefused;
use App\Domain\Export\Jobs\GenerateExportJob;
use App\Domain\Export\Jobs\GenerateRoundReport;
use App\Domain\Export\Models\Export;
use App\Domain\Export\Types\DocumentExport;
use App\Domain\Export\Types\ExportTypes;
use App\Domain\Export\Types\MultiSheetExport;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asks for an export (Phase 6 steps 0–1, D-96, D-97): checks the type, its filter (the page's own:
 * the dashboard filter for the report types) and its options, records the request (and whether
 * assessor and source details were asked for) in the audit log, and queues the work on the exports
 * queue once the row is committed. Nothing is written in the request.
 */
final class RequestExport
{
    public function __construct(
        private readonly ExportTypes $types,
    ) {}

    /**
     * @param  array<string, mixed>  $filter  as asked, by the page's URL names
     *
     * @throws ValidationException
     */
    public function execute(string $type, array $filter, ExportFormat $format, bool $includePrivate, User $actor): Export
    {
        $exporter = $this->types->get($type);
        if ($includePrivate && ! $exporter->offersPrivate()) {
            throw ExportRefused::privateNotOffered();
        }
        // A workbook of several sheets has no CSV form.
        if ($exporter instanceof MultiSheetExport && $format !== ExportFormat::Xlsx) {
            throw ExportRefused::excelOnly();
        }
        // A document comes in its one format (the round report: PDF); nothing else is a PDF.
        if ($exporter instanceof DocumentExport ? $format !== $exporter->format() : $format === ExportFormat::Pdf) {
            throw ExportRefused::wrongFormat();
        }
        $filter = $exporter->filter($filter);

        return DB::transaction(function () use ($exporter, $type, $filter, $format, $includePrivate, $actor): Export {
            $export = Export::query()->create([
                'type' => $type,
                'filter' => (object) $filter,
                'options' => ['format' => $format->value, 'include_private' => $includePrivate],
                'requested_by' => $actor->id,
            ]);
            AuditLog::record($export, $actor, 'export_requested', old: [], new: [
                'type' => $type,
                'format' => $format->value,
                'include_private' => $includePrivate,
                'filter' => $filter,
            ]);
            // The PDF round report has its own job, run where Chromium is (worker-exports).
            if ($exporter instanceof DocumentExport) {
                GenerateRoundReport::dispatch($export->id)->afterCommit();
            } else {
                GenerateExportJob::dispatch($export->id)->afterCommit();
            }

            return $export;
        });
    }
}
