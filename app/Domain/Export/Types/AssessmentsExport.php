<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Assessment\Queries\AssessmentPrivateDetailsQuery;
use App\Domain\Ingestion\DTOs\AssessmentSource;
use App\Domain\Ingestion\Queries\AssessmentSourceQuery;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Reporting\DTOs\AssessmentExportRow;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Queries\AssessmentExportQuery;
use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * "Assessments": one row per accepted or flagged assessment in the rounds the filter covers, with
 * its round, place, facility, source, visit date, the three dimension scores, overall and band
 * (each round under its own rule version). With "Include assessor and source details": the
 * assessor, device ID, visit GPS, instance ID, the link to the submission at its source and links
 * to its attachments. The default never reads those columns at all.
 */
final class AssessmentsExport implements ExportType
{
    private const COLUMNS = ['round', 'lga_code', 'lga', 'ward', 'facility_code', 'facility', 'level', 'ownership', 'source', 'visited_on', 'status', 'availability', 'consistency', 'validity', 'overall', 'band'];

    private const PRIVATE_COLUMNS = ['assessor', 'device', 'latitude', 'longitude', 'instance_id', 'source_link', 'attachments'];

    public function __construct(
        private readonly AssessmentExportQuery $figures,
        private readonly SourceRegistry $sources,
        private readonly AssessmentPrivateDetailsQuery $people,
        private readonly AssessmentSourceQuery $origins,
    ) {}

    public function key(): string
    {
        return 'assessments';
    }

    public function offersPrivate(): bool
    {
        return true;
    }

    public function headings(bool $private): array
    {
        return array_map(
            fn (string $column): string => (string) __("exports.types.assessments.columns.{$column}"),
            $private ? [...self::COLUMNS, ...self::PRIVATE_COLUMNS] : self::COLUMNS,
        );
    }

    public function count(ReportFilter $filter): int
    {
        return $this->figures->count($filter);
    }

    public function chunks(ReportFilter $filter, bool $private, int $size): iterable
    {
        /** @var array<int, string> $names source id => name */
        $names = [];
        foreach ($this->figures->chunks($filter, $size) as $chunk) {
            $people = $private ? $this->people->forAssessments(array_map(fn (AssessmentExportRow $row): int => $row->assessmentId, $chunk)) : [];
            $origins = $private ? $this->origins->forSubmissions(array_map(fn (AssessmentExportRow $row): int => $row->submissionId, $chunk), absolute: true) : [];

            yield array_map(function (AssessmentExportRow $row) use (&$names, $private, $people, $origins): array {
                $names[$row->sourceId] ??= $this->sourceName($row->sourceId);
                $cells = [
                    $row->round, $row->lgaCode, $row->lga, $row->ward, $row->facilityCode, $row->facility,
                    $row->level->label(), $row->ownership->label(), $names[$row->sourceId], $row->visitedOn,
                    (string) __("exports.types.assessments.statuses.{$row->status}"),
                    self::score($row->availability), self::score($row->consistency), self::score($row->validity), self::score($row->overall),
                    $row->overall->band === null ? null : $row->overall->bandLabel,
                ];
                if (! $private) {
                    return $cells;
                }
                $person = $people[$row->assessmentId] ?? null;
                $origin = $origins[$row->submissionId] ?? null;

                return [...$cells,
                    $person['assessor'] ?? null, $person['device'] ?? null, $person['latitude'] ?? null, $person['longitude'] ?? null,
                    $origin?->instanceId, $origin?->url, self::attachments($origin),
                ];
            }, $chunk);
        }
    }

    /** The score as shown on the dashboard (rounded at presentation only), as a number; empty when there is none. */
    private static function score(ScoreValue $score): ?float
    {
        return $score->value === null || ! is_numeric($score->display) ? null : (float) $score->display;
    }

    private static function attachments(?AssessmentSource $origin): ?string
    {
        $links = array_values(array_filter(array_map(fn (array $attachment): ?string => $attachment['url'], $origin->attachments ?? [])));

        return $links === [] ? null : implode(' ', $links);
    }

    private function sourceName(int $sourceId): string
    {
        $key = $this->sources->keyOf($sourceId);

        return $key === null ? (string) __('exports.types.assessments.unknown_source') : $this->sources->name($key);
    }
}
