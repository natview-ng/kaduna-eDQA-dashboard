<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\DTOs\StateSummary;
use App\Domain\Reporting\RoundCache;
use App\Domain\Reporting\Support\FacilityScoresSql;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Reporting\Support\Scores;
use App\Domain\Reporting\Support\Weighting;
use App\Domain\Scoring\Bands;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Presentation;
use Illuminate\Support\Facades\DB;

/**
 * Home hero (§9.2.1): the average quality score (unweighted mean of facility overalls),
 * facilities visited, LGAs covered, and how many facilities show as Strong or better and as
 * under the Review threshold. The two counts compare the displayed value (Q-26).
 */
final class StateSummaryAggregator
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly FacilityScoresSql $sql,
        private readonly RoundCache $cache,
    ) {}

    public function summary(ReportFilter $filter): StateSummary
    {
        Weighting::assertSupported();
        $rounds = $this->rounds->current($filter);

        return $this->cache->remember(RoundSelection::ids($rounds), self::class.':'.$filter->cacheKey(), function () use ($rounds, $filter): StateSummary {
            $rules = $this->sql->config($rounds);
            [$cte, $bindings] = $this->sql->cte($rounds, $filter);
            $shown = Presentation::sql('overall');

            $row = DB::selectOne(<<<SQL
                {$cte}
                SELECT avg(overall) AS overall,
                    count(*) AS facilities,
                    count(DISTINCT lga_id) AS lgas,
                    count(*) FILTER (WHERE {$shown} >= ?) AS strong,
                    count(*) FILTER (WHERE {$shown} < ?) AS weak
                FROM facility_scores
                SQL, [...$bindings, $rules->bands['strong'] ?? 101, $rules->bands['review'] ?? -1]);

            $overall = Scores::of($row?->overall, $rules);
            $strongAt = $rules?->bands['strong'];

            return new StateSummary(
                overall: $overall,
                facilitiesVisited: Scores::int($row?->facilities),
                lgasCovered: Scores::int($row?->lgas),
                facilitiesStrong: Scores::int($row?->strong),
                facilitiesWeak: Scores::int($row?->weak),
                strongAt: $strongAt,
                weakBelow: $rules?->bands['review'],
                strongGap: self::strongGap($overall->value, $strongAt),
            );
        });
    }

    /**
     * How far the average shows below Strong (D-89), from the displayed value, so the sentence
     * adds up on screen (86.1 + 3.9 = 90), the same way the counts compare it (Q-26). Both sides
     * have one decimal, so the difference is rounded only to drop float noise.
     */
    private static function strongGap(?float $overall, ?float $strongAt): ?string
    {
        if ($overall === null || $strongAt === null) {
            return null;
        }
        $gap = round($strongAt - Presentation::round($overall, Bands::DISPLAY_DP), Bands::DISPLAY_DP);

        return $gap > 0 ? ScoreValue::format($gap, Bands::DISPLAY_DP) : null;
    }
}
