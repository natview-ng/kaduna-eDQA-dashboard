<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\User;
use App\Support\Export\SpreadsheetCell;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The audit log as CSV (Phase 5 step 7, D-93): the filtered rows, newest first, written to the
 * private exports disk (SECURITY.md §7) and downloaded through a signed link. Each export is
 * itself audited (`audit_exported`, with its filters and row count); viewing the log is not. The
 * recent exports are read back from those entries, so nothing else is stored. A cell that a
 * spreadsheet would read as a formula (= + - @) is prefixed with an apostrophe.
 */
final class AuditLogExport
{
    public const DIRECTORY = 'audit';

    public const FILE_PATTERN = '/^audit-log-\d{8}-\d{6}-[a-z0-9]{6}\.csv$/';

    public function __construct(
        private readonly AuditLogQuery $query,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{file: string, rows: int}
     */
    public function export(array $filters, ?User $actor): array
    {
        $file = 'audit-log-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.csv';
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }
        fputcsv($handle, ['When (WAT)', 'Who', 'Event', 'Subject type', 'Subject', 'Reason', 'Before', 'After'], escape: '');
        $rows = 0;
        foreach ($this->query->each($filters) as $row) {
            $subject = $row['subject'];
            fputcsv($handle, array_map(SpreadsheetCell::safe(...), [
                CarbonImmutable::parse((string) $row['at'])->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                $row['actor'] ?? ($row['console'] ? 'Console' : 'System'),
                (string) $row['event'],
                is_array($subject) ? (string) $subject['type'] : '',
                is_array($subject) ? (string) ($subject['label'] ?? $subject['id'] ?? '') : '',
                (string) ($row['reason'] ?? ''),
                $row['old'] === [] ? '' : (string) json_encode($row['old'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $row['new'] === [] ? '' : (string) json_encode($row['new'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]), escape: '');
            $rows++;
        }
        rewind($handle);
        $this->disk()->put(self::DIRECTORY.'/'.$file, (string) stream_get_contents($handle));
        fclose($handle);

        AuditLog::record(null, $actor, 'audit_exported', old: [], new: ['file' => $file, 'rows' => $rows, 'filters' => array_filter($filters, fn (mixed $v): bool => $v !== null && $v !== '')]);

        return ['file' => $file, 'rows' => $rows];
    }

    /**
     * The latest exports still on disk, newest first, from their audit entries.
     *
     * @return list<array{file: string, rows: int, at: string}>
     */
    public function recent(int $limit = 5): array
    {
        $entries = DB::table('activity_log')->where('event', 'audit_exported')->orderByDesc('id')->limit(20)->get(['properties', 'created_at']);
        $recent = [];
        foreach ($entries as $entry) {
            $attributes = json_decode((string) $entry->properties, true)['attributes'] ?? [];
            $file = is_array($attributes) ? (string) ($attributes['file'] ?? '') : '';
            if (preg_match(self::FILE_PATTERN, $file) === 1 && $this->disk()->exists(self::DIRECTORY.'/'.$file)) {
                $recent[] = ['file' => $file, 'rows' => (int) ($attributes['rows'] ?? 0), 'at' => CarbonImmutable::parse((string) $entry->created_at)->toIso8601String()];
            }
            if (count($recent) === $limit) {
                break;
            }
        }

        return $recent;
    }

    public function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('exports');

        return $disk;
    }
}
