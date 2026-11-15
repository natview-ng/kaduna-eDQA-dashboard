<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Ingestion\SourceRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogRequest;
use App\Support\Audit\AuditLogExport;
use App\Support\Audit\AuditLogQuery;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Audit log page (SECURITY.md §4, Phase 5 step 7, D-93): every audited write and sign-in
 * event, newest first, 50 a page, filtered on the server. Read only: there is no edit or delete,
 * and a view is not itself audited. The recent CSV exports are listed with signed links.
 */
final class AdminAuditController extends Controller
{
    public const SIGNED_MINUTES = 30;

    public function __invoke(AuditLogRequest $request, AuditLogQuery $query, AuditLogExport $export, SourceRegistry $sources): Response
    {
        return Inertia::render('admin/audit', [
            'entries' => fn () => $query->paginate($request->filters()),
            'auditFilters' => $request->filters(),
            'actors' => fn (): array => $query->actors(),
            'events' => fn (): array => $query->events(),
            'types' => [...array_keys(AuditLogQuery::types()), 'auth'],
            'sources' => fn (): array => array_map(fn (string $key): array => ['key' => $key, 'name' => $sources->name($key)], $sources->keys()),
            'exports' => fn (): array => array_map(fn (array $file): array => [
                ...$file,
                'url' => URL::temporarySignedRoute('admin.api.audit.exports.show', now()->addMinutes(self::SIGNED_MINUTES), ['file' => $file['file']]),
            ], $export->recent()),
        ]);
    }
}
