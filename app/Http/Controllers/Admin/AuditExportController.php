<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogRequest;
use App\Jobs\ExportAuditLog;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Audit\AuditLogExport;
use App\Support\Audit\AuditLogQuery;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit log as CSV (Phase 5 step 7). POST /api/audit/export writes the filtered rows at once
 * (201, with a signed link) up to edqa.audit.export_sync_rows, or queues them (202); the page lists
 * a queued file when it is ready. GET follows a signed link. Both are audited; the log itself is
 * only ever read.
 */
final class AuditExportController extends Controller
{
    public function store(AuditLogRequest $request, AuditLogQuery $query, AuditLogExport $export, #[Config('edqa.audit.export_sync_rows')] int $syncRows): JsonResponse
    {
        $filters = $request->filters();
        unset($filters['page']);
        $actor = self::actor($request);

        if ($query->count($filters) > $syncRows) {
            ExportAuditLog::dispatch($filters, $actor?->id);

            return response()->json(['data' => ['queued' => true]], 202);
        }

        $done = $export->export($filters, $actor);

        return response()->json(['data' => [
            'queued' => false,
            'file' => $done['file'],
            'rows' => $done['rows'],
            'url' => URL::temporarySignedRoute('admin.api.audit.exports.show', now()->addMinutes(AdminAuditController::SIGNED_MINUTES), ['file' => $done['file']]),
        ]], 201);
    }

    public function download(Request $request, string $file, AuditLogExport $export): StreamedResponse
    {
        $path = AuditLogExport::DIRECTORY.'/'.$file;
        if (preg_match(AuditLogExport::FILE_PATTERN, $file) !== 1 || ! $export->disk()->exists($path)) {
            abort(404);
        }
        AuditLog::record(null, self::actor($request), 'audit_export_downloaded', old: [], new: ['file' => $file]);

        return $export->disk()->download($path, $file, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
