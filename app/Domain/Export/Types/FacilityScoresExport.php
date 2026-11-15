<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Enums\ColumnKind;
use App\Domain\Export\Support\Column;
use App\Domain\Export\Support\RoundsInView;
use App\Domain\Export\Support\ScoreCells;
use App\Domain\Export\Support\SourceNames;
use App\Domain\Reporting\Aggregators\FacilityScoresQuery;
use App\Domain\Reporting\DTOs\FacilityRow;
use App\Domain\Reporting\DTOs\FacilitySort;
use App\Domain\Reporting\Enums\Metric;
use App\Domain\Reporting\Queries\FacilitySourcesQuery;
use App\Domain\Reporting\Support\RoundSelection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "Facility scores" (the Facility view, every page): weakest first by the metric in view, its three
 * months and score, then all four metrics, each at full precision, as displayed and with its band,
 * the source(s) of the facility's assessments, and the round's status. Rows from
 * FacilityScoresQuery::each, the same SELECT as the page.
 */
final class FacilityScoresExport extends ReportExport
{
    public function __construct(
        RoundsInView $rounds,
        private readonly FacilityScoresQuery $facilities,
        private readonly FacilitySourcesQuery $sources,
        private readonly SourceNames $names,
    ) {
        parent::__construct($rounds);
    }

    public function key(): string
    {
        return 'facility_scores';
    }

    public function filter(array $input): array
    {
        $metric = Validator::make($input, ['metric' => ['nullable', Rule::enum(Metric::class)]])->validate()['metric'] ?? null;

        return [...parent::filter($input), 'metric' => is_string($metric) ? $metric : Metric::Overall->value];
    }

    public function columns(array $filter, bool $private): array
    {
        $c = fn (string $key, array $replace = []): string => (string) __("exports.types.facility_scores.columns.{$key}", $replace);
        $metric = (string) __('exports.dimensions.'.self::metric($filter)->value);

        return [
            Column::text($c('code')), Column::text($c('facility')), Column::text($c('lga')), Column::text($c('ward')),
            Column::text($c('level')), Column::text($c('ownership')), Column::text($c('round')), new Column($c('assessed_on'), ColumnKind::Date),
            Column::text($c('source')),
            ...Column::score($c('month', ['metric' => $metric, 'slot' => 1])),
            ...Column::score($c('month', ['metric' => $metric, 'slot' => 2])),
            ...Column::score($c('month', ['metric' => $metric, 'slot' => 3])),
            ...Column::score($c('metric_score', ['metric' => $metric])),
            ...Column::score((string) __('exports.dimensions.availability')),
            ...Column::score((string) __('exports.dimensions.consistency')),
            ...Column::score((string) __('exports.dimensions.validity')),
            ...Column::score((string) __('exports.dimensions.overall')),
            Column::text($c('status')),
        ];
    }

    public function count(array $filter): int
    {
        return $this->facilities->total($this->report($filter));
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        $report = $this->report($filter);
        $status = $this->rounds->statusByRound($report);
        $roundIds = RoundSelection::ids($this->rounds->of($report));

        foreach ($this->facilities->each($report, FacilitySort::default(self::metric($filter)), $size) as $rows) {
            $sources = $this->sources->forFacilities(array_map(fn (FacilityRow $row): int => $row->facilityId, $rows), $roundIds);

            yield array_map(fn (FacilityRow $row): array => [
                $row->code, $row->name, $row->lga, $row->ward, $row->level->label(), $row->ownership->label(), $row->roundLabel, $row->assessedOn,
                $this->names->list($sources[$row->facilityId] ?? []),
                ...ScoreCells::of($row->m1), ...ScoreCells::of($row->m2), ...ScoreCells::of($row->m3), ...ScoreCells::of($row->score),
                ...ScoreCells::of($row->availability), ...ScoreCells::of($row->consistency), ...ScoreCells::of($row->validity), ...ScoreCells::of($row->overall),
                $row->roundId === null ? null : ($status[$row->roundId] ?? null),
            ], $rows);
        }
    }

    /** @param  array<string, mixed>  $filter */
    private static function metric(array $filter): Metric
    {
        return Metric::tryFrom(is_string($filter['metric'] ?? null) ? $filter['metric'] : '') ?? Metric::Overall;
    }
}
