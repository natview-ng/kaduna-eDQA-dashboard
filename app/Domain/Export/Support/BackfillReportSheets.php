<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

use App\Domain\Ingestion\DTOs\BackfillReport;
use App\Domain\Round\Support\RoundLabel;

/**
 * The backfill report as Excel sheets (ARCHITECTURE.md §5.6): Summary (per source ×
 * round: pulled, accepted, flagged, quarantined, rejected, unprocessed, duplicates and duplicates
 * across sources; each source's total and the grand total), By rule × year, By rule × form version,
 * Examples (the most frequent details per hard rule), Unmatched facilities. It only lays out
 * BackfillReportQuery's figures: every number is the query's. Nothing personal: the query reads no
 * assessor, device, GPS or attachment, and the examples come from hard rules only.
 */
final class BackfillReportSheets
{
    /** @return list<Sheet> */
    public static function from(BackfillReport $report): array
    {
        $c = fn (string $key): string => (string) __("exports.types.backfill_report.columns.{$key}");
        $s = fn (string $key): string => (string) __("exports.types.backfill_report.sheets.{$key}");
        $rule = fn (string $code): string => (string) __("validation_rules.{$code}.name");

        $copies = [];
        foreach ($report->duplicates as $row) {
            $copies[$row['source']] = $row['cross_source_duplicates'];
        }

        return [
            new Sheet($s('summary'), [
                Column::text($c('source')), Column::text($c('round')),
                Column::integer($c('pulled')), Column::integer($c('accepted')), Column::integer($c('flagged')), Column::integer($c('quarantined')),
                Column::integer($c('rejected')), Column::integer($c('unprocessed')), Column::integer($c('duplicates')), Column::integer($c('cross_source')),
                Column::integer($c('copies')),
            ], array_map(fn (array $round): array => [
                $round['source'] === null ? $c('all_sources') : self::source($round['source']),
                match (true) {
                    $round['source'] === null => $c('grand_total'),
                    $round['total'] => $c('total'),
                    $round['year'] === null || $round['quarter'] === null => (string) __('backfill.report.no_round'),
                    default => RoundLabel::of($round['year'], $round['quarter']),
                },
                $round['pulled'], $round['accepted'], $round['flagged'], $round['quarantined'], $round['rejected'], $round['unprocessed'],
                $round['duplicates'], $round['cross_source'],
                // Copies of the same submission from the other source are never stored (D-60): only
                // the pull runs count them, per source, so they sit on the source's total row.
                $round['source'] !== null && $round['total'] ? ($copies[$round['source']] ?? 0) : null,
            ], $report->rounds)),

            new Sheet($s('by_year'), [Column::text($c('source')), Column::text($c('code')), Column::text($c('rule')), Column::integer($c('year')), Column::integer($c('records'))],
                array_map(fn (array $row): array => [self::source($row['source']), $row['code'], $rule($row['code']), $row['year'], $row['records']], $report->byYear)),

            new Sheet($s('by_version'), [Column::text($c('source')), Column::text($c('code')), Column::text($c('rule')), Column::text($c('version')), Column::integer($c('records'))],
                array_map(fn (array $row): array => [self::source($row['source']), $row['code'], $rule($row['code']), $row['version'], $row['records']], $report->byVersion)),

            new Sheet($s('examples'), [Column::text($c('code')), Column::text($c('rule')), Column::text($c('detail')), Column::integer($c('records'))],
                array_map(fn (array $row): array => [$row['code'], $rule($row['code']), $row['detail'], $row['records']], $report->examples)),

            new Sheet($s('unmatched'), [Column::text($c('source')), Column::text($c('facility_ref')), Column::text($c('lga_ref')), Column::integer($c('records')), Column::text($c('rounds')), Column::text($c('detail'))],
                array_map(fn (array $row): array => [
                    self::source($row['source']), $row['facility_ref'] === '' ? $c('blank') : $row['facility_ref'], $row['lga_ref'] === '' ? null : $row['lga_ref'],
                    $row['records'], $row['rounds'] === '' ? (string) __('backfill.report.no_round') : $row['rounds'], $row['detail'],
                ], $report->unmatched)),
        ];
    }

    /** A source as administrators know it ("ODK Central"); its key when config has no name. */
    private static function source(string $key): string
    {
        $name = config("edqa.sources.{$key}.name");

        return is_string($name) && $name !== '' ? $name : $key;
    }
}
