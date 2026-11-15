<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\DTOs\AssessmentExportRow;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use App\Domain\Round\Support\RoundLabel;
use App\Domain\Scoring\DTOs\RuleConfig;
use Illuminate\Support\Facades\DB;

/**
 * The figures of an export of assessments (Phase 6 step 0): one row per accepted or flagged
 * assessment in the rounds the dashboard filter covers (RoundSelection::current, the same rounds
 * as the view the export was asked from), within its LGA, level and owner filters. Each round's
 * scores are read under its own rule version (D-32), through FacilityScoresSql's
 * assessment_scores_in_view; a dimension is the mean of its non-null month scores and overall the
 * mean of the three (a month filter, D-75: that month's mean).
 *
 * Read in chunks by assessment id, so a large export never holds every row at once. Never the
 * assessor, device, visit GPS or source details: those are added, only when asked for, outside
 * Reporting (App\Domain\Export).
 */
final class AssessmentExportQuery
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
    ) {}

    public function count(ReportFilter $filter): int
    {
        Weighting::assertSupported();
        [$cte, $bindings] = $this->sql->cte($this->rounds->current($filter), $filter);

        return Scores::int(DB::selectOne("{$cte} SELECT count(*) AS total FROM assessment_scores_in_view", $bindings)?->total);
    }

    /**
     * @return iterable<list<AssessmentExportRow>> chunks of rows, in assessment id order
     */
    public function chunks(ReportFilter $filter, int $size = 1000): iterable
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);
        [$cte, $bindings] = $this->sql->cte($rounds, $filter);
        /** @var array<int, ?RuleConfig> $configs */
        $configs = [];
        foreach ($rounds as $round) {
            $configs[$round->id] = $this->sql->configFor($round);
        }
        $slot = $filter->monthSlot();
        $overall = $slot === null ? '(x.availability + x.consistency + x.validity) / 3' : "x.m{$slot}";

        $after = 0;
        do {
            $rows = DB::select(<<<SQL
                {$cte}
                SELECT x.assessment_id, a.submission_id, a.source_id, a.status, r.id AS round_id, r.year, r.quarter,
                    l.code AS lga_code, l.name AS lga, w.name AS ward, f.code, f.name, f.level, f.ownership,
                    (x.started_at AT TIME ZONE ?)::date::text AS visited_on,
                    x.availability, x.consistency, x.validity, {$overall} AS overall
                FROM assessment_scores_in_view x
                JOIN assessments a ON a.id = x.assessment_id
                JOIN rounds r ON r.id = x.round_id
                JOIN facilities f ON f.id = x.facility_id
                JOIN lgas l ON l.id = f.lga_id
                JOIN wards w ON w.id = f.ward_id
                WHERE x.assessment_id > ?
                ORDER BY x.assessment_id
                LIMIT ?
                SQL, [...$bindings, config('app.timezone'), $after, $size]);

            if ($rows === []) {
                return;
            }
            $after = (int) end($rows)->assessment_id;

            yield array_map(function (object $row) use ($configs): AssessmentExportRow {
                $rules = $configs[(int) $row->round_id] ?? null;

                return new AssessmentExportRow(
                    assessmentId: (int) $row->assessment_id,
                    submissionId: (int) $row->submission_id,
                    sourceId: (int) $row->source_id,
                    status: (string) $row->status,
                    round: RoundLabel::of((int) $row->year, (int) $row->quarter),
                    lgaCode: (string) $row->lga_code,
                    lga: (string) $row->lga,
                    ward: (string) $row->ward,
                    facilityCode: (string) $row->code,
                    facility: (string) $row->name,
                    level: FacilityLevel::from((string) $row->level),
                    ownership: Ownership::from((string) $row->ownership),
                    visitedOn: (string) $row->visited_on,
                    availability: Scores::of($row->availability, $rules),
                    consistency: Scores::of($row->consistency, $rules),
                    validity: Scores::of($row->validity, $rules),
                    overall: Scores::of($row->overall, $rules),
                );
            }, $rows);
        } while (count($rows) === $size);
    }
}
