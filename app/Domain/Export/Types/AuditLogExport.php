<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Support\Column;
use App\Support\Audit\AuditLogQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "Audit log" (the Audit log page, D-93), now on the export plumbing: every
 * matching entry, newest first, from AuditLogQuery::each with the page's filters (who, subject
 * type, source, event, dates, reason): when, who, what, the subject, the source, the reason and
 * before/after. Reading the log for an export changes nothing in it.
 */
final class AuditLogExport implements ExportType
{
    public function __construct(
        private readonly AuditLogQuery $log,
    ) {}

    public function key(): string
    {
        return 'audit_log';
    }

    public function offersPrivate(): bool
    {
        return false;
    }

    public function filter(array $input): array
    {
        $data = Validator::make($input, [
            'actor' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['nullable', Rule::in([...array_keys(AuditLogQuery::types()), 'auth'])],
            'source' => ['nullable', 'string', 'max:40'],
            'event' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ])->validate();

        return array_filter([
            'actor' => isset($data['actor']) ? (int) $data['actor'] : null,
            'type' => $data['type'] ?? null,
            'source' => $data['source'] ?? null,
            'event' => $data['event'] ?? null,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'q' => isset($data['q']) && trim((string) $data['q']) !== '' ? trim((string) $data['q']) : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function columns(array $filter, bool $private): array
    {
        $c = fn (string $key): string => (string) __("exports.types.audit_log.columns.{$key}");

        return [
            Column::dateTime($c('at')), Column::text($c('who')), Column::text($c('event')), Column::text($c('subject_type')), Column::text($c('subject')),
            Column::text($c('source')), Column::text($c('reason')), Column::text($c('before')), Column::text($c('after')),
        ];
    }

    public function count(array $filter): int
    {
        return $this->log->count($filter);
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        $chunk = [];
        foreach ($this->log->each($filter) as $row) {
            $subject = $row['subject'];
            $chunk[] = [
                CarbonImmutable::parse((string) $row['at'])->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                $row['actor'] ?? (string) __($row['console'] ? 'exports.types.audit_log.console' : 'exports.types.audit_log.system'),
                self::event((string) $row['event']),
                is_array($subject) ? (string) __('admin.audit.types.'.$subject['type']) : null,
                is_array($subject) ? (string) ($subject['label'] ?? $subject['id'] ?? '') : null,
                $row['source'],
                $row['reason'],
                $row['old'] === [] ? null : (string) json_encode($row['old'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $row['new'] === [] ? null : (string) json_encode($row['new'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            if (count($chunk) >= $size) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }

    public function describe(array $filter): array
    {
        $parts = [];
        foreach (['actor', 'type', 'source', 'event', 'from', 'to', 'q'] as $key) {
            if (isset($filter[$key])) {
                $parts[] = (string) __("exports.types.audit_log.filter.{$key}", ['value' => (string) $filter[$key]]);
            }
        }

        return [[(string) __('exports.about.filter'), $parts === [] ? (string) __('exports.filter.all') : implode(' · ', $parts)]];
    }

    /** The event as the page names it, or its key when it has no label. */
    private static function event(string $event): string
    {
        $label = __("admin.audit.events.{$event}");

        return is_string($label) && $label !== "admin.audit.events.{$event}" ? $label : $event;
    }
}
