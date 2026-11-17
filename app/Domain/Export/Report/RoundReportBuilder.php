<?php

declare(strict_types=1);

namespace App\Domain\Export\Report;

use App\Domain\Export\Support\RoundsInView;
use App\Domain\Export\Support\SourceNames;
use App\Domain\Reporting\Aggregators\AssessmentsBySourceAggregator;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\MonthSlotAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\QuarterTrendAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\Aggregators\WeakestLgasAggregator;
use App\Domain\Reporting\DTOs\MonthSlotPoint;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\DTOs\TrendPoint;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use App\Domain\Validation\Queries\QuarantineQuery;
use Carbon\CarbonImmutable;

/**
 * Gathers the PDF round report (D-101) from the dashboard's aggregators for one
 * round: no figure is computed here (hard rule 5), only picked and put in order. A closed round is
 * read with the public audience, so the report is exactly what the public dashboard shows; an open
 * round (a draft) with the admin audience, as the admin preview shows it. The data quality note
 * takes counts only from QuarantineQuery and AssessmentsBySourceAggregator.
 */
final class RoundReportBuilder
{
    public function __construct(
        private readonly StateSummaryAggregator $summary,
        private readonly DimensionAggregator $dimensions,
        private readonly OwnerSplitAggregator $owners,
        private readonly QuarterTrendAggregator $trend,
        private readonly MonthSlotAggregator $months,
        private readonly LgaTableAggregator $lgas,
        private readonly WeakestLgasAggregator $weakest,
        private readonly ImplementationPlanAggregator $plan,
        private readonly AssessmentsBySourceAggregator $bySource,
        private readonly QuarantineQuery $quarantine,
        private readonly SourceNames $sources,
        private readonly RoundsInView $rounds,
        private readonly RuleVersionForRound $versions,
    ) {}

    /** The filter the report reads its round with: public for a closed round, admin for a draft. */
    public static function filterFor(Round $round): ReportFilter
    {
        return new ReportFilter(
            year: $round->year,
            quarter: $round->quarter,
            audience: $round->status === RoundStatus::Open ? ReportAudience::Admin : ReportAudience::Public,
        );
    }

    public function build(Round $round): RoundReport
    {
        $filter = self::filterFor($round);
        // Quality by quarter: the round's year up to the round itself, never a later round.
        $trend = array_values(array_filter(
            $this->trend->trend($filter),
            fn (TrendPoint $point): bool => $point->year === $round->year && $point->quarter <= $round->quarter,
        ));
        $months = array_values(array_filter(
            $this->months->series($filter),
            fn (MonthSlotPoint $point): bool => $point->roundId === $round->id,
        ));
        $summary = $this->summary->summary($filter);

        return new RoundReport(
            roundId: $round->id,
            round: $round->label(),
            generatedOn: CarbonImmutable::now()->setTimezone((string) config('app.timezone'))->format('j M Y'),
            draft: $round->status === RoundStatus::Open,
            summary: $summary,
            dimensions: $this->dimensions->dimensions($filter),
            owners: $this->owners->split($filter),
            trend: $trend,
            trendTakeaway: $this->trend->takeaway($trend),
            months: $months,
            lgas: $this->lgas->rows($filter),
            weakest: $this->weakest->weakest($filter),
            plan: $this->plan->plan($filter),
            quality: $this->quality($round, $filter, $summary->facilitiesVisited),
            method: $this->method($round, $filter),
        );
    }

    private function quality(Round $round, ReportFilter $filter, int $accepted): DataQuality
    {
        $held = $this->quarantine->heldBackCounts($round->id);
        $acceptedBySource = [];
        foreach ($this->bySource->counts($filter) as $count) {
            $acceptedBySource[$count->sourceId] = $count->assessments;
        }
        $ids = array_values(array_unique([...array_keys($acceptedBySource), ...array_keys($held['sources'])]));
        sort($ids);

        return new DataQuality(
            accepted: $accepted,
            quarantined: $held['total'],
            rules: array_map(fn (array $rule): array => [
                'code' => $rule['code'],
                'name' => (string) __("validation_rules.{$rule['code']}.name"),
                'count' => $rule['count'],
            ], $held['rules']),
            sources: array_map(fn (int $id): array => [
                'name' => (string) $this->sources->of($id),
                'accepted' => $acceptedBySource[$id] ?? 0,
                'quarantined' => $held['sources'][$id] ?? 0,
            ], $ids),
        );
    }

    /** @return list<array{label: string, value: string}> */
    private function method(Round $round, ReportFilter $filter): array
    {
        $line = fn (string $key, string $value): array => ['label' => (string) __("report.method.{$key}"), 'value' => $value];

        return [
            $line('formula', (string) __('report.method.formula_text')),
            $line('bands', $this->bands($round)),
            $line('rule_version', $this->rounds->ruleVersions($filter)),
            $line('rounding', (string) __('report.method.rounding_modes.'.config('edqa.presentation.rounding'))),
            $line('aggregation', (string) __('exports.about.aggregation_methods.'.config('edqa.aggregation.weighting'))),
            $line('sources', $this->sources->configured()),
            $line('published', (string) __('report.method.published_text')),
        ];
    }

    /** "Strong: 90 and above · Acceptable: 80 to under 90 · …", from the round's rule version. */
    private function bands(Round $round): string
    {
        try {
            $bands = $this->versions->config($round)->bands;
        } catch (NoPublishedRuleVersion) {
            return (string) __('exports.about.no_version');
        }
        $n = fn (float $bound): string => rtrim(rtrim(number_format($bound, 1, '.', ''), '0'), '.');

        return (string) __('report.method.bands_text', [
            'strong' => $n($bands['strong']),
            'acceptable' => $n($bands['acceptable']),
            'review' => $n($bands['review']),
        ]);
    }
}
