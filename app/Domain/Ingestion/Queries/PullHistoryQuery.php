<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\Enums\PullOutcome;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Models\PullRun;
use App\Domain\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Data sources page's combined history (ARCHITECTURE.md §9.3): every source's runs, newest
 * first, 25 a page, filtered by source (a configured key), trigger and outcome ("running" for
 * one not finished). Each run names its source as administrators know it (null for a source no
 * longer configured). Who triggered it is the administrator's name, or null for the schedule, the
 * webhook and the console. The duration is split here into minutes and seconds, so the page only
 * lays it out.
 */
final class PullHistoryQuery
{
    public const PER_PAGE = 25;

    public const OUTCOMES = ['succeeded', 'failed', 'skipped_not_configured', 'running'];

    public function __construct(
        private readonly SourceRegistry $sources,
    ) {}

    /**
     * @param  array{source?: ?string, trigger?: ?string, outcome?: ?string}  $filters
     * @return LengthAwarePaginator<int, array{id: int, source: ?array{key: string, name: string}, started_at: string, trigger: string, triggered_by: ?string, fetched: int, parsed: int, accepted: int, flagged: int, quarantined: int, duplicates: int, cross_source_duplicates: int, deletions_detected: int, duration: ?array{minutes: int, seconds: int}, outcome: string, error: ?string}>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array{id: int, source: ?array{key: string, name: string}, started_at: string, trigger: string, triggered_by: ?string, fetched: int, parsed: int, accepted: int, flagged: int, quarantined: int, duplicates: int, cross_source_duplicates: int, deletions_detected: int, duration: ?array{minutes: int, seconds: int}, outcome: string, error: ?string}> */
        return $this->filtered($filters)->paginate(self::PER_PAGE)->withQueryString()->through(fn (PullRun $run): array => $this->row($run));
    }

    /** @param  array{source?: ?string, trigger?: ?string, outcome?: ?string, from?: ?string, to?: ?string}  $filters */
    public function count(array $filters): int
    {
        return $this->filtered($filters)->count();
    }

    /**
     * Every matching run, newest first, a chunk at a time: the pull history export,
     * the same rows as the page.
     *
     * @param  array{source?: ?string, trigger?: ?string, outcome?: ?string, from?: ?string, to?: ?string}  $filters
     * @return iterable<list<array<string, mixed>>>
     */
    public function each(array $filters, int $chunk = 1000): iterable
    {
        $offset = 0;
        do {
            $runs = $this->filtered($filters)->offset($offset)->limit($chunk)->get();
            if ($runs->isEmpty()) {
                return;
            }
            $offset += $runs->count();

            yield array_values($runs->map(fn (PullRun $run): array => $this->row($run))->all());
        } while ($runs->count() === $chunk);
    }

    /**
     * @param  array{source?: ?string, trigger?: ?string, outcome?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Builder<PullRun>
     */
    private function filtered(array $filters): Builder
    {
        $trigger = PullTrigger::tryFrom((string) ($filters['trigger'] ?? ''));
        $outcome = $filters['outcome'] ?? null;
        $source = $filters['source'] ?? null;

        $query = PullRun::query()->with('triggeredBy:id,name')->orderByDesc('started_at')->orderByDesc('id');
        if (is_string($source)) {
            $query->where('source_id', $this->sources->idOrNull($source) ?? 0);
        }
        if ($trigger !== null) {
            $query->where('trigger_type', $trigger);
        }
        if ($outcome === 'running') {
            $query->whereNull('outcome');
        } elseif (is_string($outcome) && PullOutcome::tryFrom($outcome) !== null) {
            $query->where('outcome', $outcome);
        }
        // A date range (the export's year and quarter): started within it, whole days.
        if (is_string($filters['from'] ?? null)) {
            $query->where('started_at', '>=', CarbonImmutable::parse($filters['from'], (string) config('app.timezone'))->startOfDay());
        }
        if (is_string($filters['to'] ?? null)) {
            $query->where('started_at', '<', CarbonImmutable::parse($filters['to'], (string) config('app.timezone'))->addDay()->startOfDay());
        }

        return $query;
    }

    /** @return array{id: int, source: ?array{key: string, name: string}, started_at: string, trigger: string, triggered_by: ?string, fetched: int, parsed: int, accepted: int, flagged: int, quarantined: int, duplicates: int, cross_source_duplicates: int, deletions_detected: int, duration: ?array{minutes: int, seconds: int}, outcome: string, error: ?string} */
    private function row(PullRun $run): array
    {
        $seconds = $run->finished_at === null ? null : max(0, (int) $run->started_at->diffInSeconds($run->finished_at));
        $key = $this->sources->keyOf((int) $run->source_id);

        return [
            'id' => $run->id,
            'source' => $key === null ? null : ['key' => $key, 'name' => $this->sources->name($key)],
            'started_at' => $run->started_at->toIso8601String(),
            'trigger' => $run->trigger_type->value,
            'triggered_by' => $run->triggeredBy?->name,
            'fetched' => $run->fetched,
            'parsed' => $run->parsed,
            'accepted' => $run->accepted,
            'flagged' => $run->flagged,
            'quarantined' => $run->quarantined,
            'duplicates' => $run->duplicates,
            'cross_source_duplicates' => (int) $run->cross_source_duplicates,
            'deletions_detected' => (int) $run->deletions_detected,
            'duration' => $seconds === null ? null : ['minutes' => intdiv($seconds, 60), 'seconds' => $seconds % 60],
            'outcome' => $run->outcome->value ?? 'running',
            'error' => $run->error,
        ];
    }
}
