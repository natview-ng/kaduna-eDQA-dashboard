<?php

declare(strict_types=1);

namespace App\Domain\Export\Actions;

use App\Domain\Export\Enums\ExportFormat;
use App\Domain\Export\Enums\ExportStatus;
use App\Domain\Export\Models\Export;
use App\Domain\Export\Support\ExportDescription;
use App\Domain\Export\Support\ExportWriter;
use App\Domain\Export\Types\DocumentExport;
use App\Domain\Export\Types\ExportTypes;
use App\Domain\Export\Types\MultiSheetExport;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Writes a queued export (Phase 6 step 0, D-96), on the exports queue only (GenerateExportJob):
 * running, then the file in chunks with the progress kept on the row (0–99 while writing, 100
 * when done), then done with its row count, path and expiry, or failed with a plain message (the
 * detail goes to the log, never to the page). Both outcomes are in the audit log. The figures
 * are the admin audience's: an export is admin-only (D-80), so the open round counts, as in the
 * admin preview it was asked from.
 */
final class GenerateExport
{
    public function __construct(
        private readonly ExportTypes $types,
        private readonly ExportWriter $writer,
        private readonly ExportDescription $description,
    ) {}

    public function execute(int $exportId): void
    {
        $export = Export::query()->find($exportId);
        if ($export === null || $export->status->terminal()) {
            return;
        }
        $export->update(['status' => ExportStatus::Running, 'progress' => 0, 'error' => null]);
        $format = ExportFormat::from($export->format());
        $path = self::path($export, $format);

        try {
            $type = $this->types->get($export->type);
            $filter = $export->filter;
            // A document (the PDF round report) names its own file: the round, DRAFT when open.
            if ($type instanceof DocumentExport) {
                $path = "{$export->id}/{$type->fileName($filter)}.{$format->extension()}";
            }
            $total = $type->count($filter);
            $progress = function (int $written) use ($export, $total): void {
                $percent = $total === 0 ? 99 : min(99, intdiv($written * 100, $total));
                if ($percent > $export->progress) {
                    $export->update(['progress' => $percent]);
                }
            };
            $about = fn (int $count): array => $this->description->about($export, $type, $count);
            $rows = match (true) {
                $type instanceof DocumentExport => $this->document($type, $filter, $path, function (int $percent) use ($export): void {
                    if (min(99, $percent) > $export->progress) {
                        $export->update(['progress' => min(99, $percent)]);
                    }
                }),
                $type instanceof MultiSheetExport => $this->writer->writeSheets($type->sheets($filter, $export->includesPrivate()), $path, $about, $progress),
                default => $this->writer->write($type, $filter, $export->includesPrivate(), $format, $path, max(1, (int) config('edqa.exports.chunk_rows')), $progress, $about),
            };
        } catch (Throwable $e) {
            report($e);
            Storage::disk(ExportWriter::DISK)->delete($path);
            $export->update(['status' => ExportStatus::Failed, 'error' => (string) __('exports.errors.failed')]);
            AuditLog::record($export, null, 'export_failed', old: [], new: ['type' => $export->type, 'include_private' => $export->includesPrivate()]);

            return;
        }

        $export->update([
            'status' => ExportStatus::Done,
            'progress' => 100,
            'row_count' => $rows,
            'path' => $path,
            'expires_at' => CarbonImmutable::now()->addDays((int) config('edqa.exports.retention_days')),
        ]);
        AuditLog::record($export, null, 'export_completed', old: [], new: [
            'type' => $export->type,
            'rows' => $rows,
            'file' => basename($path),
            'include_private' => $export->includesPrivate(),
        ]);
    }

    /**
     * Writes a document on the exports disk, its progress in percent; its page count stands for
     * the rows.
     *
     * @param  array<string, mixed>  $filter
     * @param  \Closure(int): void  $progress
     */
    private function document(DocumentExport $type, array $filter, string $path, \Closure $progress): int
    {
        $disk = Storage::disk(ExportWriter::DISK);
        $disk->makeDirectory(dirname($path));

        return $type->write($filter, $disk->path($path), $progress);
    }

    /**
     * "{id}/assessments-20261115-0930.csv"; "-with-assessor-details" when private details are
     * in it, so the file says so wherever it ends up.
     */
    public static function path(Export $export, ExportFormat $format): string
    {
        $stamp = ($export->created_at ?? CarbonImmutable::now())->setTimezone((string) config('app.timezone'))->format('Ymd-Hi');
        $private = $export->includesPrivate() ? '-with-assessor-details' : '';

        return "{$export->id}/{$export->type}-{$stamp}{$private}.{$format->extension()}";
    }
}
