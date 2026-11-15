<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Ward;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\Models\SubmissionAttachment;
use App\Domain\Ingestion\Models\SubmissionSource;
use App\Domain\Round\Models\Round;
use App\Domain\Round\Support\RoundLabel;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Reads the audit trail for the Audit log page and its export (Phase 5 step 7, D-93). Read only:
 * activity_log is append-only (SECURITY.md §4), and nothing here or anywhere writes to it except
 * AuditLog/AuthAudit appending.
 *
 * Filters: actor (the causer), subject type (one of TYPES: a subject model, or the "auth" log),
 * source (the `source` a pull, reconcile or backfill records), event, a date range (WAT, whole
 * days), and free text on the reason. Rows say who, what, when, which subject (with a link where
 * the admin area has a page for it), the reason, and before/after.
 */
final class AuditLogQuery
{
    public const PER_PAGE = 50;

    /** Auth events (log "auth"), labelled on the page: login, failed login, lockout, unlock, … */
    public const AUTH_EVENTS = ['login', 'login-failed', 'lockout', 'unlock', 'password-changed', 'login-new-device', 'logout'];

    private const QUARANTINE = 'App\Domain\Validation\Models\QuarantinedRecord';

    private const SUBMISSION = 'App\Domain\Ingestion\Models\Submission';

    /**
     * Subject type => the models it covers. Quarantine records and raw submissions are named by
     * their morph class only (QUARANTINE, SUBMISSION): the log compares the stored subject type and
     * never reads those tables (CLAUDE.md hard rule 6; the architecture test keeps the classes in
     * ingestion, validation and Data issues).
     *
     * @return array<string, list<string>>
     */
    public static function types(): array
    {
        return [
            'assessment' => [Assessment::class, ItemResponse::class, AssessmentScore::class],
            'quarantine' => [self::QUARANTINE],
            'facility' => [Facility::class, Ward::class],
            'round' => [Round::class],
            'rule_version' => [ScoringRuleVersion::class],
            'user' => [User::class],
            'source' => [PullRun::class, self::SUBMISSION, SubmissionAttachment::class, SubmissionSource::class],
        ];
    }

    /** The subject type activity_log stores for a model class (its morph alias, or the class). */
    private static function morph(string $class): string
    {
        return Relation::getMorphAlias($class);
    }

    /**
     * @param  array{actor?: ?int, type?: ?string, source?: ?string, event?: ?string, from?: ?string, to?: ?string, q?: ?string, page?: ?int}  $filters
     * @return LengthAwarePaginator<array-key, array<string, mixed>>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $page = $this->filtered($filters)->paginate(self::PER_PAGE, page: max(1, (int) ($filters['page'] ?? 1)));
        $labels = $this->subjectLabels($page->items());

        return $page->through(fn (object $row): array => $this->row($row, $labels));
    }

    /** @param  array<string, mixed>  $filters */
    public function count(array $filters): int
    {
        return $this->filtered($filters)->count();
    }

    /**
     * Every matching row, newest first, in chunks: for the CSV.
     *
     * @param  array<string, mixed>  $filters
     * @return iterable<array<string, mixed>>
     */
    public function each(array $filters): iterable
    {
        // 500 rows at a time, each batch's subjects named with one query per kind.
        $batch = [];
        foreach ($this->filtered($filters)->lazyByIdDesc(500, 'a.id', 'id') as $row) {
            $batch[] = $row;
            if (count($batch) === 500) {
                yield from $this->rows($batch);
                $batch = [];
            }
        }
        yield from $this->rows($batch);
    }

    /**
     * @param  list<object>  $batch
     * @return list<array<string, mixed>>
     */
    private function rows(array $batch): array
    {
        $labels = $this->subjectLabels($batch);

        return array_map(fn (object $row): array => $this->row($row, $labels), $batch);
    }

    /** @return list<array{id: int, name: string}> everyone who appears as an actor */
    public function actors(): array
    {
        return User::query()->whereIn('id', DB::table('activity_log')->whereNotNull('causer_id')->distinct()->select('causer_id'))
            ->orderBy('name')->get(['id', 'name'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all();
    }

    /** @return list<string> every event recorded, alphabetically */
    public function events(): array
    {
        return DB::table('activity_log')->distinct()->orderBy('event')->pluck('event')->filter()->map(fn (mixed $event): string => (string) $event)->values()->all();
    }

    /** @param  array<string, mixed>  $filters */
    private function filtered(array $filters): Builder
    {
        $query = DB::table('activity_log as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.causer_id')
            ->select(['a.id', 'a.log_name', 'a.event', 'a.subject_type', 'a.subject_id', 'a.properties', 'a.created_at', 'u.name as actor'])
            ->orderByDesc('a.id');

        if (($filters['actor'] ?? null) !== null) {
            $query->where('a.causer_id', (int) $filters['actor']);
        }
        $type = $filters['type'] ?? null;
        if ($type === 'auth') {
            $query->where('a.log_name', 'auth');
        } elseif (is_string($type) && isset(self::types()[$type])) {
            $query->whereIn('a.subject_type', array_map(self::morph(...), self::types()[$type]));
            if ($type === 'user') {
                $query->where('a.log_name', '!=', 'auth');
            }
        }
        if (is_string($filters['source'] ?? null)) {
            $query->whereRaw("a.properties->'attributes'->>'source' = ?", [$filters['source']]);
        }
        if (is_string($filters['event'] ?? null)) {
            $query->where('a.event', $filters['event']);
        }
        if (is_string($filters['from'] ?? null)) {
            $query->where('a.created_at', '>=', CarbonImmutable::parse($filters['from'])->startOfDay());
        }
        if (is_string($filters['to'] ?? null)) {
            $query->where('a.created_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->startOfDay());
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->whereRaw("a.properties->>'reason' ILIKE ?", ['%'.addcslashes($q, '%_\\').'%']);
        }

        return $query;
    }

    /**
     * @param  array<string, array<int, array{label: string, url: ?string}>>  $labels
     * @return array<string, mixed>
     */
    private function row(object $row, array $labels): array
    {
        /** @var array<string, mixed> $properties */
        $properties = json_decode((string) $row->properties, true) ?: [];
        $type = $this->typeOf($row);
        $subject = $row->subject_id === null ? null : ($labels[(string) $row->subject_type][(int) $row->subject_id] ?? null);

        return [
            'id' => (int) $row->id,
            'at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            'actor' => $row->actor === null ? null : (string) $row->actor,
            'console' => ($properties['source'] ?? null) === 'console',
            'event' => (string) $row->event,
            'auth' => $row->log_name === 'auth',
            'subject' => $type === null ? null : [
                'type' => $type,
                'id' => $row->subject_id === null ? null : (int) $row->subject_id,
                'label' => $subject['label'] ?? null,
                'url' => $subject['url'] ?? null,
            ],
            'source' => is_array($properties['attributes'] ?? null) && is_string($properties['attributes']['source'] ?? null) ? $properties['attributes']['source'] : null,
            'reason' => is_string($properties['reason'] ?? null) ? $properties['reason'] : null,
            'old' => is_array($properties['old'] ?? null) ? $properties['old'] : [],
            'new' => is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [],
        ];
    }

    private function typeOf(object $row): ?string
    {
        if ($row->log_name === 'auth') {
            return 'auth';
        }
        foreach (self::types() as $type => $classes) {
            foreach ($classes as $class) {
                if (self::morph($class) === $row->subject_type) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * A name and an admin link for the subjects that have one, one query per kind of subject.
     *
     * @param  list<object>  $rows
     * @return array<string, array<int, array{label: string, url: ?string}>> morph class => id => label/url
     */
    private function subjectLabels(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row->subject_type !== null && $row->subject_id !== null) {
                $ids[(string) $row->subject_type][] = (int) $row->subject_id;
            }
        }
        $labels = [];
        foreach ($ids as $morph => $list) {
            $labels[$morph] = match ($morph) {
                (new Facility)->getMorphClass() => Facility::query()->whereIn('id', $list)->pluck('name', 'id')
                    ->map(fn (mixed $name, int $id): array => ['label' => (string) $name, 'url' => "/admin/facilities/{$id}"])->all(),
                (new Ward)->getMorphClass() => Ward::query()->whereIn('id', $list)->pluck('name', 'id')
                    ->map(fn (mixed $name): array => ['label' => (string) $name, 'url' => '/admin/facilities'])->all(),
                (new Round)->getMorphClass() => Round::query()->whereIn('id', $list)->get(['id', 'year', 'quarter'])
                    ->mapWithKeys(fn (Round $round): array => [$round->id => ['label' => RoundLabel::of($round->year, $round->quarter), 'url' => '/admin/rounds']])->all(),
                (new ScoringRuleVersion)->getMorphClass() => ScoringRuleVersion::query()->whereIn('id', $list)->pluck('version', 'id')
                    ->map(fn (mixed $version): array => ['label' => "v{$version}", 'url' => '/admin/scoring-rules'])->all(),
                (new Assessment)->getMorphClass() => DB::table('assessments')->whereIn('assessments.id', $list)->join('facilities', 'facilities.id', '=', 'assessments.facility_id')
                    ->get(['assessments.id', 'assessments.facility_id', 'facilities.name'])
                    ->mapWithKeys(fn (object $a): array => [(int) $a->id => ['label' => (string) $a->name, 'url' => '/admin/facilities/'.$a->facility_id]])->all(),
                // Named by number only: the log never reads the quarantine table (hard rule 6).
                self::morph(self::QUARANTINE) => collect($list)->mapWithKeys(fn (int $id): array => [$id => ['label' => "#{$id}", 'url' => '/admin/data-issues']])->all(),
                (new User)->getMorphClass() => User::query()->whereIn('id', $list)->pluck('name', 'id')
                    ->map(fn (mixed $name): array => ['label' => (string) $name, 'url' => null])->all(),
                default => [],
            };
        }

        return $labels;
    }
}
