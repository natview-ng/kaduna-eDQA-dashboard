<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use App\Domain\Round\Models\Round;
use App\Domain\Round\Support\RoundLabel;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The Facility list page: the master list, searched on name, code, ward and LGA
 * (ILIKE, served by the pg_trgm indexes) and filtered by LGA, ward, level, ownership and active,
 * 25 a page from the server. Each row carries its visits (accepted and flagged assessments), the
 * ones in an open round (what deactivating would strand), whether any assessment at all holds its
 * code, and its latest score: the overall of the latest round it was assessed in, read under that
 * round's rule version (FacilityRoundOverallsQuery) and banded by it. Counting is Reporting's
 * (hard rule 5); admin only.
 */
final class FacilityListQuery
{
    public const PER_PAGE = 25;

    public function __construct(
        private readonly FacilityRoundOverallsQuery $overalls,
        private readonly RuleVersionForRound $versions,
    ) {}

    /**
     * @param  array{q?: ?string, lga?: ?string, ward?: ?int, level?: ?string, ownership?: ?string, active?: ?string, page?: ?int}  $filters
     * @return LengthAwarePaginator<array-key, array{id: int, code: string, name: string, ward: array{id: int, name: string}, lga: array{id: int, code: string, name: string}, level: string, ownership: string, active: bool, lat: ?string, lng: ?string, visits: int, open_round_visits: int, code_locked: bool, latest: array{round: string, score: ScoreValue}|null}>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = DB::table('facilities as f')
            ->join('wards as w', 'w.id', '=', 'f.ward_id')
            ->join('lgas as l', 'l.id', '=', 'f.lga_id')
            ->select(['f.id', 'f.code', 'f.name', 'f.level', 'f.ownership', 'f.is_active', 'f.lat', 'f.lng', 'w.id as ward_id', 'w.name as ward_name', 'l.id as lga_id', 'l.code as lga_code', 'l.name as lga_name'])
            ->selectRaw("(SELECT count(*) FROM assessments a WHERE a.facility_id = f.id AND a.status IN ('accepted', 'flagged')) AS visits")
            ->selectRaw("(SELECT count(*) FROM assessments a JOIN rounds r ON r.id = a.round_id WHERE a.facility_id = f.id AND r.status = 'open' AND a.status IN ('accepted', 'flagged')) AS open_round_visits")
            ->selectRaw('EXISTS (SELECT 1 FROM assessments a WHERE a.facility_id = f.id) AS code_locked');

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('f.name', 'ilike', $like)->orWhere('f.code', 'ilike', $like)->orWhere('w.name', 'ilike', $like)->orWhere('l.name', 'ilike', $like));
        }
        $query->when(($filters['lga'] ?? null) !== null, fn ($w) => $w->where('l.code', $filters['lga']))
            ->when(($filters['ward'] ?? null) !== null, fn ($w) => $w->where('w.id', $filters['ward']))
            ->when(($filters['level'] ?? null) !== null, fn ($w) => $w->where('f.level', $filters['level']))
            ->when(($filters['ownership'] ?? null) !== null, fn ($w) => $w->where('f.ownership', $filters['ownership']))
            ->when(($filters['active'] ?? null) === 'yes', fn ($w) => $w->where('f.is_active', true))
            ->when(($filters['active'] ?? null) === 'no', fn ($w) => $w->where('f.is_active', false))
            ->orderBy('l.name')->orderBy('f.name')->orderBy('f.id');

        $page = $query->paginate(self::PER_PAGE, page: max(1, (int) ($filters['page'] ?? 1)));
        $latest = $this->latest(array_map(fn (object $row): int => (int) $row->id, $page->items()));

        return $page->through(fn (object $row): array => [
            'id' => (int) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'ward' => ['id' => (int) $row->ward_id, 'name' => (string) $row->ward_name],
            'lga' => ['id' => (int) $row->lga_id, 'code' => (string) $row->lga_code, 'name' => (string) $row->lga_name],
            'level' => (string) $row->level,
            'ownership' => (string) $row->ownership,
            'active' => (bool) $row->is_active,
            'lat' => $row->lat === null ? null : (string) $row->lat,
            'lng' => $row->lng === null ? null : (string) $row->lng,
            'visits' => (int) $row->visits,
            'open_round_visits' => (int) $row->open_round_visits,
            'code_locked' => (bool) $row->code_locked,
            'latest' => $latest[(int) $row->id] ?? null,
        ]);
    }

    /**
     * Each facility's overall in the latest round it was assessed in, banded under that round's
     * version. One query for the overalls, one for the rounds, however many facilities.
     *
     * @param  list<int>  $facilityIds
     * @return array<int, array{round: string, score: ScoreValue}>
     */
    private function latest(array $facilityIds): array
    {
        if ($facilityIds === []) {
            return [];
        }
        $rows = $this->overalls->forFacilities($facilityIds);
        $rounds = Round::query()->whereIn('id', array_unique(array_column($rows, 'round_id')))->get()->keyBy('id');

        $latest = [];
        foreach ($rows as $row) {
            $round = $rounds->get($row['round_id']);
            if (! $round instanceof Round) {
                continue;
            }
            $order = $round->year * 10 + $round->quarter;
            if (! isset($latest[$row['facility_id']]) || $order > $latest[$row['facility_id']]['order']) {
                $latest[$row['facility_id']] = ['order' => $order, 'round' => $round, 'overall' => $row['overall']];
            }
        }

        $result = [];
        foreach ($latest as $facilityId => $found) {
            try {
                $config = $this->versions->config($found['round']);
            } catch (NoPublishedRuleVersion) {
                continue;
            }
            $result[$facilityId] = ['round' => RoundLabel::of($found['round']->year, $found['round']->quarter), 'score' => ScoreValue::from($found['overall'], $config)];
        }

        return $result;
    }
}
