<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Support\Column;
use App\Domain\Export\Support\SourceNames;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Round\Support\RoundLabel;
use App\Domain\Validation\Queries\QuarantineQuery;
use App\Domain\Validation\RuleCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "Data issues" (the Data issues page's quarantine): each record with its rule codes, names and
 * details as the page shows them, its source, status and round, from QuarantineQuery with the
 * page's filters (state, rule, round, LGA, source), and the dashboard's year and quarter when
 * given (the rounds they cover). Level and owner do not apply: a held record has no facility yet.
 */
final class DataIssuesExport implements ExportType
{
    public function __construct(
        private readonly QuarantineQuery $quarantine,
        private readonly SourceNames $names,
        private readonly SourceRegistry $sources,
        private readonly RoundSelection $rounds,
    ) {}

    public function key(): string
    {
        return 'data_issues';
    }

    public function offersPrivate(): bool
    {
        return false;
    }

    public function filter(array $input): array
    {
        $codes = array_map(fn (array $rule): string => $rule['code'], app(RuleCatalogue::class)->all());
        $data = Validator::make($input, [
            'state' => ['nullable', Rule::in(QuarantineQuery::STATES)],
            'rule' => ['nullable', Rule::in($codes)],
            'lga' => ['nullable', 'string', Rule::exists('lgas', 'code')],
            'round' => ['nullable', 'integer', Rule::exists('rounds', 'id')],
            'source' => ['nullable', 'string', Rule::in($this->sources->keys())],
            'year' => ['nullable', 'integer', 'between:2000,2100', 'required_with:quarter'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
        ])->validate();

        return array_filter([
            'state' => $data['state'] ?? null,
            'rule' => $data['rule'] ?? null,
            'lga' => $data['lga'] ?? null,
            'round' => isset($data['round']) ? (int) $data['round'] : null,
            'source' => $data['source'] ?? null,
            'year' => isset($data['year']) ? (int) $data['year'] : null,
            'quarter' => isset($data['quarter']) ? (int) $data['quarter'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function columns(array $filter, bool $private): array
    {
        $c = fn (string $key): string => (string) __("exports.types.data_issues.columns.{$key}");

        return [
            Column::integer($c('id')), Column::text($c('instance_id')), Column::text($c('source')), Column::text($c('codes')), Column::text($c('names')),
            Column::text($c('details')), Column::text($c('status')), Column::text($c('resolution')), Column::text($c('facility_ref')), Column::text($c('lga_ref')),
            Column::text($c('round')), Column::dateTime($c('received_at')), Column::dateTime($c('resolved_at')),
        ];
    }

    public function count(array $filter): int
    {
        return $this->quarantine->exportCount($this->query($filter));
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        $labels = [];
        foreach (DB::table('rounds')->get(['id', 'year', 'quarter']) as $round) {
            $labels[(int) $round->id] = RoundLabel::of((int) $round->year, (int) $round->quarter);
        }

        foreach ($this->quarantine->exportRows($this->query($filter), $size) as $rows) {
            yield array_map(fn (array $row): array => [
                $row['id'], $row['instance_id'], $this->names->of($row['source_id']), implode('; ', $row['codes']), implode('; ', $row['names']),
                implode('; ', $row['details']), (string) __("enums.quarantine_status.{$row['status']}"),
                $row['resolution'] === null ? null : (string) __("enums.quarantine_resolution.{$row['resolution']}"),
                $row['facility_ref'], $row['lga_ref'], $row['round_id'] === null ? null : ($labels[$row['round_id']] ?? null),
                self::local($row['received_at']), self::local($row['resolved_at']),
            ], $rows);
        }
    }

    public function describe(array $filter): array
    {
        $parts = [];
        foreach (['state', 'rule', 'lga', 'source'] as $key) {
            if (isset($filter[$key])) {
                $parts[] = (string) __("exports.types.data_issues.filter.{$key}", ['value' => (string) $filter[$key]]);
            }
        }
        if (isset($filter['round'])) {
            $round = DB::table('rounds')->where('id', (int) $filter['round'])->first(['year', 'quarter']);
            $parts[] = $round === null ? '' : RoundLabel::of((int) $round->year, (int) $round->quarter);
        }
        if (isset($filter['year'])) {
            $parts[] = (string) __('exports.filter.year', ['year' => (int) $filter['year']]).(isset($filter['quarter']) ? ' · Q'.(int) $filter['quarter'] : '');
        }
        $parts = array_values(array_filter($parts));

        return [[(string) __('exports.about.filter'), $parts === [] ? (string) __('exports.filter.all') : implode(' · ', $parts)]];
    }

    /**
     * The page's filters in QuarantineQuery's terms: the source as its row id, a year (and
     * quarter) as the rounds it covers.
     *
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    private function query(array $filter): array
    {
        $query = [
            'state' => $filter['state'] ?? null,
            'rule' => $filter['rule'] ?? null,
            'lga' => $filter['lga'] ?? null,
            'round' => $filter['round'] ?? null,
            'source_id' => isset($filter['source']) ? $this->sources->idOrNull((string) $filter['source']) : null,
        ];
        if (isset($filter['year'])) {
            $report = ReportFilter::fromArray(['year' => $filter['year'], 'quarter' => $filter['quarter'] ?? null], ReportAudience::Admin);
            $query['rounds'] = RoundSelection::ids($this->rounds->series($report));
        }

        return $query;
    }

    private static function local(?string $at): ?string
    {
        return $at === null ? null : CarbonImmutable::parse($at)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i');
    }
}
