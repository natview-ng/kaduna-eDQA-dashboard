<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Support\Column;
use App\Domain\Export\Support\RoundsInView;
use App\Domain\Export\Support\ScoreCells;
use App\Domain\Reporting\Aggregators\CoverageAggregator;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * "State summary" (Home and Monitor): the headline, the three dimensions, the public/private split
 * and coverage by LGA and level, one measure per row, every number from the page's own aggregator.
 */
final class StateSummaryExport extends ReportExport
{
    public function __construct(
        RoundsInView $rounds,
        private readonly StateSummaryAggregator $summary,
        private readonly DimensionAggregator $dimensions,
        private readonly OwnerSplitAggregator $owners,
        private readonly CoverageAggregator $coverage,
    ) {
        parent::__construct($rounds);
    }

    public function key(): string
    {
        return 'state_summary';
    }

    public function columns(array $filter, bool $private): array
    {
        $c = fn (string $key): string => (string) __("exports.types.state_summary.columns.{$key}");

        return [Column::text($c('section')), Column::text($c('measure')), Column::text($c('group')), Column::integer($c('facilities')), ...Column::score($c('score')), Column::text($c('status'))];
    }

    public function count(array $filter): int
    {
        $rows = 0;
        foreach ($this->chunks($filter, false, PHP_INT_MAX) as $chunk) {
            $rows += count($chunk);
        }

        return $rows;
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        $report = $this->report($filter);
        $status = $this->rounds->status($report);
        $t = fn (string $key, array $replace = []): string => (string) __("exports.types.state_summary.{$key}", $replace);
        $row = fn (string $section, string $measure, string $group, ?int $facilities, ?ScoreValue $score) => [
            $section, $measure, $group, $facilities, ...($score === null ? [null, null, null] : ScoreCells::of($score)), $status,
        ];

        $summary = $this->summary->summary($report);
        $state = $t('groups.state');
        $rows = [
            $row($t('sections.headline'), $t('measures.average'), $state, $summary->facilitiesVisited, $summary->overall),
            $row($t('sections.headline'), $t('measures.facilities_visited'), $state, $summary->facilitiesVisited, null),
            $row($t('sections.headline'), $t('measures.lgas_covered'), $state, $summary->lgasCovered, null),
            $row($t('sections.headline'), $t('measures.facilities_strong', ['value' => self::threshold($summary->strongAt)]), $state, $summary->facilitiesStrong, null),
            $row($t('sections.headline'), $t('measures.facilities_weak', ['value' => self::threshold($summary->weakBelow)]), $state, $summary->facilitiesWeak, null),
        ];

        $dimensions = $this->dimensions->dimensions($report);
        foreach (['availability', 'consistency', 'validity', 'overall'] as $dimension) {
            $rows[] = $row($t('sections.dimensions'), (string) __("exports.dimensions.{$dimension}"), $state, null, $dimensions->{$dimension});
        }
        if ($dimensions->weakest !== null) {
            $rows[] = $row($t('sections.dimensions'), $t('measures.weakest'), $dimensions->weakest->label(), null, null);
        }

        $split = $this->owners->split($report);
        foreach (['public' => $split->public, 'private' => $split->private] as $owner => $group) {
            foreach (['overall', 'availability', 'consistency', 'validity'] as $dimension) {
                $rows[] = $row($t('sections.owners'), (string) __("exports.dimensions.{$dimension}"), (string) __("enums.ownership.{$owner}"), $group->facilities, $group->{$dimension});
            }
        }

        $coverage = $this->coverage->coverage($report);
        foreach ($coverage->perLga as $lga) {
            $rows[] = $row($t('sections.coverage_lga'), $t('measures.facilities_visited'), $lga->name, $lga->facilities, null);
        }
        $rows[] = [$t('sections.coverage_lga'), $t('measures.lga_mean'), $state, null, $coverage->stateMean, is_numeric($coverage->stateMeanDisplay) ? (float) $coverage->stateMeanDisplay : null, null, $status];
        foreach ($coverage->byLevel as $level) {
            $rows[] = $row($t('sections.coverage_level'), (string) __('exports.dimensions.overall'), $level->level->label(), $level->facilities, $level->overall);
        }

        yield $rows;
    }

    private static function threshold(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
