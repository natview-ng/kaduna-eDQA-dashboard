<?php

declare(strict_types=1);

namespace App\Domain\Export\Actions;

use App\Domain\Export\Enums\ExportStatus;
use App\Domain\Export\Models\Export;
use App\Domain\Export\Support\ExportWriter;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Retention (Phase 6 step 0): an export's file is deleted edqa.exports.retention_days (7) after it
 * was written, and its row marked expired, so files that left the system do not also pile up in
 * it. Run daily by ExpireExportsJob. Each expiry is in the audit log.
 */
final class ExpireExports
{
    /** @return int how many exports expired */
    public function execute(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subDays((int) config('edqa.exports.retention_days'));
        $expired = 0;

        Export::query()
            ->where('status', ExportStatus::Done->value)
            ->where(fn ($query) => $query->where('expires_at', '<=', $now)->orWhere('updated_at', '<=', $cutoff))
            ->orderBy('id')
            ->each(function (Export $export) use (&$expired): void {
                if ($export->path !== null) {
                    Storage::disk(ExportWriter::DISK)->delete($export->path);
                }
                $file = $export->path === null ? null : basename($export->path);
                $export->update(['status' => ExportStatus::Expired, 'path' => null]);
                AuditLog::record($export, null, 'export_expired', old: ['file' => $file], new: ['status' => ExportStatus::Expired->value]);
                $expired++;
            });

        return $expired;
    }
}
