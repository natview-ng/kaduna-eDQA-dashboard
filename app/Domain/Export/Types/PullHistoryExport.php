<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Support\Column;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Queries\PullHistoryQuery;
use App\Domain\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "Pull history" (the Data sources page): every run with its source, trigger, who started it, the
 * counters, duration and outcome, from PullHistoryQuery with the page's filters (source, trigger,
 * outcome), and a year and quarter when given (runs started in that calendar quarter or year).
 */
final class PullHistoryExport implements ExportType
{
    public function __construct(
        private readonly PullHistoryQuery $history,
        private readonly SourceRegistry $sources,
    ) {}

    public function key(): string
    {
        return 'pull_history';
    }

    public function offersPrivate(): bool
    {
        return false;
    }

    public function filter(array $input): array
    {
        $data = Validator::make($input, [
            'source' => ['nullable', 'string', Rule::in($this->sources->keys())],
            'trigger' => ['nullable', Rule::enum(PullTrigger::class)],
            'outcome' => ['nullable', Rule::in(PullHistoryQuery::OUTCOMES)],
            'year' => ['nullable', 'integer', 'between:2000,2100', 'required_with:quarter'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
        ])->validate();

        return array_filter([
            'source' => $data['source'] ?? null,
            'trigger' => $data['trigger'] ?? null,
            'outcome' => $data['outcome'] ?? null,
            'year' => isset($data['year']) ? (int) $data['year'] : null,
            'quarter' => isset($data['quarter']) ? (int) $data['quarter'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function columns(array $filter, bool $private): array
    {
        $c = fn (string $key): string => (string) __("exports.types.pull_history.columns.{$key}");
        $columns = [Column::integer($c('id')), Column::text($c('source')), Column::dateTime($c('started_at')), Column::text($c('trigger')), Column::text($c('triggered_by'))];
        foreach (['fetched', 'parsed', 'accepted', 'flagged', 'quarantined', 'duplicates', 'cross_source_duplicates', 'deletions_detected', 'duration'] as $counter) {
            $columns[] = Column::integer($c($counter));
        }

        return [...$columns, Column::text($c('outcome')), Column::text($c('error'))];
    }

    public function count(array $filter): int
    {
        return $this->history->count($this->query($filter));
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        foreach ($this->history->each($this->query($filter), $size) as $runs) {
            yield array_map(fn (array $run): array => [
                $run['id'], $run['source']['name'] ?? (string) __('exports.unknown_source'),
                CarbonImmutable::parse($run['started_at'])->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                (string) __("enums.pull_trigger.{$run['trigger']}"), $run['triggered_by'],
                $run['fetched'], $run['parsed'], $run['accepted'], $run['flagged'], $run['quarantined'], $run['duplicates'],
                $run['cross_source_duplicates'], $run['deletions_detected'],
                $run['duration'] === null ? null : $run['duration']['minutes'] * 60 + $run['duration']['seconds'],
                (string) __("exports.types.pull_history.outcomes.{$run['outcome']}"), $run['error'],
            ], $runs);
        }
    }

    public function describe(array $filter): array
    {
        $parts = [];
        if (isset($filter['source'])) {
            $parts[] = $this->sources->name((string) $filter['source']);
        }
        if (isset($filter['trigger'])) {
            $parts[] = (string) __('enums.pull_trigger.'.$filter['trigger']);
        }
        if (isset($filter['outcome'])) {
            $parts[] = (string) __('exports.types.pull_history.outcomes.'.$filter['outcome']);
        }
        if (isset($filter['year'])) {
            $parts[] = (string) __('exports.filter.year', ['year' => (int) $filter['year']]).(isset($filter['quarter']) ? ' · Q'.(int) $filter['quarter'] : '');
        }

        return [[(string) __('exports.about.filter'), $parts === [] ? (string) __('exports.filter.all') : implode(' · ', $parts)]];
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{source?: ?string, trigger?: ?string, outcome?: ?string, from?: ?string, to?: ?string}
     */
    private function query(array $filter): array
    {
        $query = ['source' => $filter['source'] ?? null, 'trigger' => $filter['trigger'] ?? null, 'outcome' => $filter['outcome'] ?? null];
        if (isset($filter['year'])) {
            $year = (int) $filter['year'];
            $quarter = isset($filter['quarter']) ? (int) $filter['quarter'] : null;
            $start = CarbonImmutable::create($year, $quarter === null ? 1 : 3 * $quarter - 2, 1);
            $query['from'] = $start->toDateString();
            $query['to'] = ($quarter === null ? $start->endOfYear() : $start->addMonths(3)->subDay())->toDateString();
        }

        return $query;
    }
}
