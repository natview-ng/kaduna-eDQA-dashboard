<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\DTOs\FacilityPage;
use App\Domain\Reporting\DTOs\FacilityRow;
use App\Domain\Reporting\DTOs\FacilitySort;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use App\Domain\Round\Models\Round;
use App\Domain\Round\Support\RoundLabel;
use App\Domain\Scoring\DTOs\RuleConfig;
use Illuminate\Support\Facades\DB;

/**
 * The facility view (§9.2.2): one row per facility in view, paginated on the server (50 a page),
 * sortable on any column, weakest first by default. The metric switch picks which scores fill the
 * month cells and the score column; nulls sort last; ties fall back to the facility name.
 */
final class FacilityScoresQuery
{
    public const PER_PAGE = 50;

    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    public function paginate(ReportFilter $filter, FacilitySort $sort, int $page = 1, int $perPage = self::PER_PAGE): FacilityPage
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);
        $page = max(1, $page);
        $key = self::class.':'.$filter->cacheKey().':'.$sort->cacheKey().":{$page}:{$perPage}";

        return $this->cache->remember(RoundSelection::ids($rounds), $key, function () use ($rounds, $filter, $sort, $page, $perPage): FacilityPage {
            [$sql, $bindings, $rules] = $this->select($rounds, $filter, $sort);
            $rows = DB::select("{$sql} LIMIT ? OFFSET ?", [...$bindings, $perPage, ($page - 1) * $perPage]);

            [$cte, $cteBindings] = $this->sql->cte($rounds, $filter);
            $total = $rows === [] ? $this->count($cte, $cteBindings) : Scores::int($rows[0]->total);

            return new FacilityPage(array_map(fn (object $row): FacilityRow => self::row($row, $rules), $rows), $total, $page, $perPage);
        });
    }

    /**
     * Every row of the view, in the page's order, a chunk at a time and not cached: the facility
     * scores export. The same SELECT as paginate(), so an export never computes a
     * number the page does not.
     *
     * @return iterable<list<FacilityRow>>
     */
    public function each(ReportFilter $filter, FacilitySort $sort, int $chunk = 1000): iterable
    {
        Weighting::assertSupported();
        [$sql, $bindings, $rules] = $this->select($this->rounds->current($filter), $filter, $sort);
        $offset = 0;
        do {
            $rows = DB::select("{$sql} LIMIT ? OFFSET ?", [...$bindings, $chunk, $offset]);
            if ($rows === []) {
                return;
            }
            $offset += count($rows);

            yield array_map(fn (object $row): FacilityRow => self::row($row, $rules), $rows);
        } while (count($rows) === $chunk);
    }

    /** How many facilities are in view (the export's progress). */
    public function total(ReportFilter $filter): int
    {
        [$cte, $bindings] = $this->sql->cte($this->rounds->current($filter), $filter);

        return $this->count($cte, $bindings);
    }

    /**
     * @param  list<Round>  $rounds
     * @return array{0: string, 1: list<mixed>, 2: ?RuleConfig}
     */
    private function select(array $rounds, ReportFilter $filter, FacilitySort $sort): array
    {
        $rules = $this->sql->config($rounds);
        [$cte, $bindings] = $this->sql->cte($rounds, $filter);
        $metric = $sort->metric;
        $order = $this->orderColumn($sort);
        $direction = $sort->direction === 'desc' ? 'DESC' : 'ASC';

        $sql = <<<SQL
            {$cte}
            SELECT fs.facility_id, f.code, f.name, l.name AS lga, w.name AS ward, fs.ownership, fs.level,
                r.id AS round_id, r.year, r.quarter, (fs.last_visit AT TIME ZONE ?)::date::text AS assessed_on,
                fs.{$metric->monthColumn(1)} AS m1, fs.{$metric->monthColumn(2)} AS m2, fs.{$metric->monthColumn(3)} AS m3,
                fs.{$metric->scoreColumn()} AS score, fs.overall, fs.availability, fs.consistency, fs.validity,
                count(*) OVER () AS total
            FROM facility_scores fs
            JOIN facilities f ON f.id = fs.facility_id
            JOIN lgas l ON l.id = fs.lga_id
            JOIN wards w ON w.id = fs.ward_id
            LEFT JOIN rounds r ON r.id = fs.last_round_id
            ORDER BY {$order} {$direction} NULLS LAST, f.name ASC, fs.facility_id ASC
            SQL;

        return [$sql, [...$bindings, config('app.timezone')], $rules];
    }

    private static function row(object $row, ?RuleConfig $rules): FacilityRow
    {
        return new FacilityRow(
            facilityId: (int) $row->facility_id,
            code: (string) $row->code,
            name: (string) $row->name,
            lga: (string) $row->lga,
            ward: (string) $row->ward,
            ownership: Ownership::from((string) $row->ownership),
            level: FacilityLevel::from((string) $row->level),
            roundLabel: RoundLabel::of((int) $row->year, (int) $row->quarter),
            assessedOn: (string) $row->assessed_on,
            m1: Scores::of($row->m1, $rules),
            m2: Scores::of($row->m2, $rules),
            m3: Scores::of($row->m3, $rules),
            score: Scores::of($row->score, $rules),
            overall: Scores::of($row->overall, $rules),
            availability: Scores::of($row->availability, $rules),
            consistency: Scores::of($row->consistency, $rules),
            validity: Scores::of($row->validity, $rules),
            roundId: $row->round_id === null ? null : (int) $row->round_id,
        );
    }

    /** A fixed column for each sort key: request text never reaches SQL. */
    private function orderColumn(FacilitySort $sort): string
    {
        return match ($sort->column) {
            'facility' => 'f.name',
            'lga' => 'l.name',
            'ward' => 'w.name',
            'owner' => 'fs.ownership',
            'assessed' => 'fs.last_visit',
            'm1' => 'fs.'.$sort->metric->monthColumn(1),
            'm2' => 'fs.'.$sort->metric->monthColumn(2),
            'm3' => 'fs.'.$sort->metric->monthColumn(3),
            'overall' => 'fs.overall',
            default => 'fs.'.$sort->metric->scoreColumn(),
        };
    }

    /**
     * Only for a page past the end, which returns no rows to read the total from.
     *
     * @param  list<mixed>  $bindings
     */
    private function count(string $cte, array $bindings): int
    {
        return Scores::int(DB::selectOne("{$cte} SELECT count(*) AS total FROM facility_scores", $bindings)?->total);
    }
}
