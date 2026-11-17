<?php

declare(strict_types=1);

namespace App\Domain\Export\Report;

use App\Domain\Reporting\DTOs\GroupScores;
use App\Domain\Reporting\DTOs\LgaScore;
use App\Domain\Reporting\DTOs\MonthSlotPoint;
use App\Domain\Reporting\DTOs\TrendPoint;
use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * The round report as print HTML (Phase 6 step 5, D-101): resources/views/reports/round.blade.php
 * with its charts drawn here (SvgChart) from the report's figures. The HTML is complete and
 * self-contained (tokens, fonts, charts inline), so Chromium needs nothing else and loads no URL.
 * The running header and footer are Chromium's page templates (page numbers, the round, DRAFT).
 */
final class RoundReportView
{
    /** A chart beside its table, in half the page width. */
    private const HALF = 360;

    public function html(RoundReport $report): string
    {
        return view('reports.round', [
            'report' => $report,
            'charts' => $this->charts($report),
            'rootCss' => ReportStyle::rootCss(),
            'fontCss' => ReportStyle::fontCss(),
        ])->render();
    }

    /** Chromium's header template: the round and, for an open round, DRAFT. */
    public function header(RoundReport $report): string
    {
        return view('reports.round-header', ['report' => $report])->render();
    }

    /** Chromium's footer template: "Page 3 of 9" and the date it was made. */
    public function footer(RoundReport $report): string
    {
        return view('reports.round-footer', ['report' => $report])->render();
    }

    /** @return array<string, string> */
    private function charts(RoundReport $report): array
    {
        $d = $report->dimensions;
        $label = fn (string $key): string => (string) __("report.columns.{$key}");
        $row = fn (string $key, ScoreValue $score): array => ['label' => $label($key), 'value' => $score->value, 'display' => $score->display, 'class' => "series-{$key}"];
        $series = fn (string $key, string $class, array $scores): array => [
            'name' => $label($key),
            'class' => $class,
            'values' => array_map(fn (ScoreValue $s): ?float => $s->value, $scores),
            'displays' => array_map(fn (ScoreValue $s): string => $s->display, $scores),
        ];
        $groupScores = fn (GroupScores $g): array => [$g->availability, $g->consistency, $g->validity, $g->overall];
        $dimensionLabels = [$label('availability'), $label('consistency'), $label('validity'), $label('overall')];

        $trend = $report->trend;
        $months = $report->months;

        return [
            'dimensions' => SvgChart::bars(
                (string) __('report.headline.chart', ['round' => $report->round]),
                [$row('availability', $d->availability), $row('consistency', $d->consistency), $row('validity', $d->validity), $row('overall', $d->overall)],
                $report->summary->strongAt,
                self::HALF,
            ),
            'owners' => SvgChart::groupedBars(
                (string) __('report.owners.chart', ['round' => $report->round]),
                $dimensionLabels,
                [
                    $series('public', 'pub', $groupScores($report->owners->public)),
                    $series('private', 'priv', $groupScores($report->owners->private)),
                ],
            ),
            'trend' => SvgChart::line(
                (string) __('report.trend.chart', ['year' => $trend === [] ? '' : (string) $trend[0]->year]),
                array_map(fn (TrendPoint $p): string => $p->label, $trend),
                [
                    $series('overall', 'series-overall', array_map(fn (TrendPoint $p): ScoreValue => $p->overall, $trend)),
                    $series('public', 'pub', array_map(fn (TrendPoint $p): ScoreValue => $p->public, $trend)),
                    $series('private', 'priv', array_map(fn (TrendPoint $p): ScoreValue => $p->private, $trend)),
                ],
            ),
            'months' => SvgChart::groupedBars(
                (string) __('report.months.chart', ['round' => $report->round]),
                array_map(fn (MonthSlotPoint $p): string => $p->chartLabel, $months),
                [
                    $series('availability', 'series-availability', array_map(fn (MonthSlotPoint $p): ScoreValue => $p->availability, $months)),
                    $series('consistency', 'series-consistency', array_map(fn (MonthSlotPoint $p): ScoreValue => $p->consistency, $months)),
                    $series('validity', 'series-validity', array_map(fn (MonthSlotPoint $p): ScoreValue => $p->validity, $months)),
                    $series('overall', 'series-overall', array_map(fn (MonthSlotPoint $p): ScoreValue => $p->overall, $months)),
                ],
            ),
            'weakest' => SvgChart::bars(
                (string) __('report.weakest.chart', ['round' => $report->round]),
                array_map(fn (LgaScore $l): array => ['label' => $l->name, 'value' => $l->overall->value, 'display' => $l->overall->display, 'class' => 'band-'.($l->overall->band->value ?? 'none')], $report->weakest),
                $report->summary->strongAt,
                self::HALF,
            ),
        ];
    }
}
