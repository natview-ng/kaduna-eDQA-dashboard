<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Assessment\Queries\AssessmentPrivateDetailsQuery;
use App\Domain\Export\Support\Column;
use App\Domain\Export\Support\RoundsInView;
use App\Domain\Export\Support\SourceNames;
use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Ingestion\DTOs\AssessmentSource;
use App\Domain\Ingestion\Queries\AssessmentSourceQuery;
use App\Domain\Reporting\Queries\ItemResponseExportQuery;
use App\Domain\Reporting\Support\ItemResult;
use App\Domain\Round\MonthSlot;
use App\Domain\Scoring\Enums\Dimension;

/**
 * "Item responses", long format: one row per facility × round × dimension × month × item, its
 * result as the facility drawer shows it, the source and the round's status. With "Include assessor
 * and source details": the assessor, device ID, visit GPS, instance ID, the link at source and the
 * attachment links of each row's assessment; the default never reads them.
 */
final class ItemResponsesExport extends ReportExport
{
    public function __construct(
        RoundsInView $rounds,
        private readonly ItemResponseExportQuery $items,
        private readonly SourceNames $names,
        private readonly AssessmentPrivateDetailsQuery $people,
        private readonly AssessmentSourceQuery $origins,
    ) {
        parent::__construct($rounds);
    }

    public function key(): string
    {
        return 'item_responses';
    }

    public function offersPrivate(): bool
    {
        return true;
    }

    public function columns(array $filter, bool $private): array
    {
        $c = fn (string $key): string => (string) __("exports.types.item_responses.columns.{$key}");
        $columns = [
            Column::text($c('code')), Column::text($c('facility')), Column::text($c('lga')), Column::text($c('ward')),
            Column::text($c('level')), Column::text($c('ownership')), Column::text($c('round')), Column::text($c('source')),
            Column::text($c('dimension')), Column::integer($c('slot')), Column::text($c('month')), Column::text($c('item_code')), Column::text($c('item')),
            Column::text($c('result')), Column::integer($c('checks_passed')), Column::integer($c('checks_total')), Column::text($c('status')),
        ];
        if ($private) {
            foreach (['assessor', 'device', 'latitude', 'longitude', 'instance_id', 'source_link', 'attachments'] as $key) {
                $columns[] = Column::text((string) __("exports.private_columns.{$key}"));
            }
        }

        return $columns;
    }

    public function count(array $filter): int
    {
        return $this->items->count($this->report($filter));
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        $report = $this->report($filter);
        $status = $this->rounds->statusByRound($report);
        // About $size rows a chunk: an assessment holds roughly 30 to 100 items.
        foreach ($this->items->chunks($report, max(1, intdiv($size, 50))) as $rows) {
            $people = $private ? $this->people->forAssessments(array_values(array_unique(array_column($rows, 'assessment_id')))) : [];
            $origins = $private ? $this->origins->forSubmissions(array_values(array_unique(array_column($rows, 'submission_id'))), absolute: true) : [];

            yield array_map(function (array $row) use ($status, $private, $people, $origins): array {
                $dimension = Dimension::from($row['dimension']);
                $cells = [
                    $row['code'], $row['facility'], $row['lga'], $row['ward'],
                    FacilityLevel::from($row['level'])->label(), Ownership::from($row['ownership'])->label(), $row['round'], $this->names->of($row['source_id']),
                    $dimension->label(), $row['slot'], MonthSlot::label($row['year'], $row['quarter'], $row['slot']), $row['item_code'], ItemResult::label($dimension, $row['item_code']),
                    self::result($row['result']), $row['checks']['passed'] ?? null, $row['checks']['total'] ?? null,
                    $status[$row['round_id']] ?? null,
                ];
                if (! $private) {
                    return $cells;
                }
                $person = $people[$row['assessment_id']] ?? null;
                $origin = $origins[$row['submission_id']] ?? null;

                return [...$cells,
                    $person['assessor'] ?? null, $person['device'] ?? null, $person['latitude'] ?? null, $person['longitude'] ?? null,
                    $origin?->instanceId, $origin?->url, self::attachments($origin),
                ];
            }, $rows);
        }
    }

    /** Pass, Fail, N/A in words; a count of checks passed stays a number; empty when unreadable. */
    private static function result(?string $result): ?string
    {
        return match ($result) {
            null => null,
            'pass', 'fail', 'na' => (string) __("exports.results.{$result}"),
            default => $result,
        };
    }

    private static function attachments(?AssessmentSource $origin): ?string
    {
        $links = array_values(array_filter(array_map(fn (array $attachment): ?string => $attachment['url'], $origin->attachments ?? [])));

        return $links === [] ? null : implode(' ', $links);
    }
}
