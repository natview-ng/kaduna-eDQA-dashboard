<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Support\Audit\AuditLogExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A large audit-log export (over edqa.audit.export_sync_rows rows), written by the worker; the
 * Audit log page lists it once it is on disk (Phase 5 step 7). The export audits itself.
 */
final class ExportAuditLog implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, mixed>  $filters */
    public function __construct(
        public readonly array $filters,
        public readonly ?int $actorId,
    ) {
        // The exports queue (DEPLOY.md: default, rescore, exports, low).
        $this->onQueue('exports');
    }

    public function handle(AuditLogExport $export): void
    {
        $export->export($this->filters, $this->actorId === null ? null : User::query()->find($this->actorId));
    }
}
