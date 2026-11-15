<?php

declare(strict_types=1);

namespace App\Domain\Export\Actions;

use App\Domain\Export\Enums\ExportFormat;
use App\Domain\Export\Exceptions\ExportRefused;
use App\Domain\Export\Jobs\GenerateExportJob;
use App\Domain\Export\Models\Export;
use App\Domain\Export\Types\ExportTypes;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Asks for an export (Phase 6 step 0, D-96): checks the type and its options, records the request
 * (and whether assessor and source details were asked for) in the audit log, and queues the work
 * on the exports queue once the row is committed. Nothing is written in the request.
 */
final class RequestExport
{
    public function __construct(
        private readonly ExportTypes $types,
    ) {}

    public function execute(string $type, ReportFilter $filter, ExportFormat $format, bool $includePrivate, User $actor): Export
    {
        $exporter = $this->types->get($type);
        if ($includePrivate && ! $exporter->offersPrivate()) {
            throw ExportRefused::privateNotOffered();
        }

        return DB::transaction(function () use ($type, $filter, $format, $includePrivate, $actor): Export {
            $export = Export::query()->create([
                'type' => $type,
                'filter' => (object) $filter->toQuery(),
                'options' => ['format' => $format->value, 'include_private' => $includePrivate],
                'requested_by' => $actor->id,
            ]);
            AuditLog::record($export, $actor, 'export_requested', old: [], new: [
                'type' => $type,
                'format' => $format->value,
                'include_private' => $includePrivate,
                'filter' => $filter->toQuery(),
            ]);
            GenerateExportJob::dispatch($export->id)->afterCommit();

            return $export;
        });
    }
}
