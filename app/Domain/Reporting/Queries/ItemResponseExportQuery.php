<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\ItemResult;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\RuleConfig;
use Illuminate\Support\Facades\DB;

/**
 * The item responses export, long format: one row per facility × round ×
 * dimension × month × item, for the accepted and flagged assessments in the rounds the filter
 * covers (the page's rounds), within its LGA, level and owner (and month, D-75). Each item's result
 * is ItemResult's, as the facility drawer shows it: pass, fail, N/A, or the checks passed for an
 * item made of several (D-67). Raw answers, no aggregate: nothing here is a score.
 */
final class ItemResponseExportQuery
{
    private const DIMENSIONS = ['availability' => 1, 'consistency' => 2, 'validity' => 3];

    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
    ) {}

    /** Rows the export will hold: distinct items across the assessments in view. */
    public function count(ReportFilter $filter): int
    {
        [$where, $bindings] = $this->where($filter);
        if ($where === null) {
            return 0;
        }

        return Scores::int(DB::selectOne(<<<SQL
            SELECT count(*) AS total FROM (
                SELECT DISTINCT i.assessment_id, i.dimension, i.month_slot, i.item_code
                FROM item_responses i JOIN assessments a ON a.id = i.assessment_id JOIN facilities f ON f.id = a.facility_id
                WHERE {$where}
            ) items
            SQL, $bindings)?->total);
    }

    /**
     * @return iterable<list<array{assessment_id: int, submission_id: int, source_id: int, round_id: int, round: string, year: int, quarter: int, code: string, facility: string, lga: string, ward: string, level: string, ownership: string, dimension: string, slot: int, item_code: string, result: ?string, checks: array{passed: int, total: int}|null}>>
     */
    public function chunks(ReportFilter $filter, int $assessmentsPerChunk = 200): iterable
    {
        [$where, $bindings] = $this->where($filter);
        if ($where === null) {
            return;
        }
        /** @var array<int, Round> $rounds */
        $rounds = [];
        foreach ($this->rounds->current($filter) as $round) {
            $rounds[$round->id] = $round;
        }
        /** @var array<int, ?RuleConfig> $configs */
        $configs = [];

        $after = 0;
        do {
            $assessments = DB::select(<<<SQL
                SELECT a.id, a.submission_id, a.source_id, a.round_id, f.code, f.name, f.level, f.ownership, l.name AS lga, w.name AS ward
                FROM assessments a JOIN facilities f ON f.id = a.facility_id JOIN lgas l ON l.id = f.lga_id JOIN wards w ON w.id = f.ward_id
                WHERE a.id > ? AND a.id IN (SELECT DISTINCT i.assessment_id FROM item_responses i JOIN assessments a ON a.id = i.assessment_id JOIN facilities f ON f.id = a.facility_id WHERE {$where})
                ORDER BY a.id
                LIMIT ?
                SQL, [$after, ...$bindings, $assessmentsPerChunk]);
            if ($assessments === []) {
                return;
            }
            $after = (int) end($assessments)->id;

            $slot = $filter->monthSlot();
            $responses = DB::table('item_responses')->whereIn('assessment_id', array_map(fn (object $a): int => (int) $a->id, $assessments))
                ->when($slot !== null, fn ($q) => $q->where('month_slot', $slot))
                ->orderBy('assessment_id')->orderBy('id')
                ->get(['assessment_id', 'dimension', 'month_slot', 'item_code', 'value', 'is_applicable']);
            /** @var array<int, array<string, list<array{0: string, 1: bool}>>> $items assessment => "dimension|slot|code" => checks */
            $items = [];
            foreach ($responses as $response) {
                $items[(int) $response->assessment_id]["{$response->dimension}|{$response->month_slot}|{$response->item_code}"][] = [(string) $response->value, (bool) $response->is_applicable];
            }

            $rows = [];
            foreach ($assessments as $assessment) {
                $round = $rounds[(int) $assessment->round_id];
                $rules = $configs[$round->id] ??= $this->sql->configFor($round);
                $keys = array_keys($items[(int) $assessment->id] ?? []);
                usort($keys, fn (string $x, string $y): int => self::order($x) <=> self::order($y));
                foreach ($keys as $key) {
                    [$dimension, $slotNumber, $code] = explode('|', $key, 3);
                    $item = ItemResult::ofChecks($items[(int) $assessment->id][$key], $rules);
                    $rows[] = [
                        'assessment_id' => (int) $assessment->id,
                        'submission_id' => (int) $assessment->submission_id,
                        'source_id' => (int) $assessment->source_id,
                        'round_id' => $round->id,
                        'round' => $round->label(),
                        'year' => $round->year,
                        'quarter' => $round->quarter,
                        'code' => (string) $assessment->code,
                        'facility' => (string) $assessment->name,
                        'lga' => (string) $assessment->lga,
                        'ward' => (string) $assessment->ward,
                        'level' => (string) $assessment->level,
                        'ownership' => (string) $assessment->ownership,
                        'dimension' => $dimension,
                        'slot' => (int) $slotNumber,
                        'item_code' => $code,
                        'result' => $item['result'],
                        'checks' => $item['checks'],
                    ];
                }
            }

            yield $rows;
        } while (count($assessments) === $assessmentsPerChunk);
    }

    /**
     * Dimension in form order, then month, then item code.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private static function order(string $key): array
    {
        [$dimension, $slot, $code] = explode('|', $key, 3);

        return [self::DIMENSIONS[$dimension] ?? 9, (int) $slot, $code];
    }

    /** @return array{0: ?string, 1: list<mixed>} the WHERE over item_responses i, assessments a, facilities f; null when no round is in view */
    private function where(ReportFilter $filter): array
    {
        $roundIds = RoundSelection::ids($this->rounds->current($filter));
        if ($roundIds === []) {
            return [null, []];
        }
        [$facilityWhere, $bindings] = FacilityScoresSql::filterSql($filter);
        $slot = $filter->monthSlot();
        $placeholders = implode(', ', array_fill(0, count($roundIds), '?'));

        return [
            "a.round_id IN ({$placeholders}) AND a.status IN ('accepted', 'flagged')".($slot === null ? '' : " AND i.month_slot = {$slot}").$facilityWhere,
            [...$roundIds, ...$bindings],
        ];
    }
}
