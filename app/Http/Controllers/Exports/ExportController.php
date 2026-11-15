<?php

declare(strict_types=1);

namespace App\Http\Controllers\Exports;

use App\Domain\Export\Actions\RequestExport;
use App\Domain\Export\Enums\ExportFormat;
use App\Domain\Export\Enums\ExportStatus;
use App\Domain\Export\Exceptions\ExportRefused;
use App\Domain\Export\Models\Export;
use App\Domain\Export\Support\ExportWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\StoreExportRequest;
use App\Http\Resources\Exports\ExportResource;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Queued exports (Phase 6 step 0, D-96; ARCHITECTURE.md §8). POST queues one and answers its id
 * (202); GET follows it, with a signed link once done; the download is signed and needs a signed-in
 * administrator, streams from the private disk and is audited. Exports are admin-only (D-80): no
 * public route reaches any of this.
 */
final class ExportController extends Controller
{
    public function store(StoreExportRequest $request, RequestExport $action): JsonResponse
    {
        $export = $action->execute($request->string('type')->toString(), $request->exportFilter(), $request->exportFormat(), $request->includePrivate(), self::actor($request));

        return response()->json(['data' => ['export_id' => $export->id]], 202);
    }

    public function show(Export $export): ExportResource
    {
        return new ExportResource($export);
    }

    public function download(Request $request, Export $export): StreamedResponse
    {
        if ($export->status === ExportStatus::Expired) {
            throw ExportRefused::expired();
        }
        $disk = Storage::disk(ExportWriter::DISK);
        if ($export->status !== ExportStatus::Done || $export->path === null || ! $disk->exists($export->path)) {
            throw ExportRefused::notReady();
        }
        AuditLog::record($export, self::actor($request), 'export_downloaded', old: [], new: ['file' => basename($export->path), 'include_private' => $export->includesPrivate()]);

        return $disk->download($export->path, basename($export->path), [
            'Content-Type' => ExportFormat::from($export->format())->mime(),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
