<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\DTOs\SourceCount;
use Illuminate\Support\Facades\DB;

/**
 * The Rounds page (step 4): each round's accepted and flagged assessments per source, most first.
 * Withdrawn ones are left out, as everywhere (D-62). It reads assessments.source_id only (the
 * page names the sources from the registry, which Reporting may not read). One query, however many
 * rounds; admin only, so nothing is cached.
 */
final class RoundAssessmentCountsQuery
{
    /**
     * @param  list<int>  $roundIds
     * @return array<int, array{total: int, bySource: list<SourceCount>}> round ID => the round's total and its counts per source
     */
    public function bySource(array $roundIds): array
    {
        $counts = array_fill_keys($roundIds, ['total' => 0, 'bySource' => []]);
        if ($roundIds === []) {
            return $counts;
        }

        $rows = DB::select(<<<'SQL'
            SELECT round_id, source_id, count(*) AS assessments
            FROM assessments
            WHERE round_id = ANY(?::bigint[]) AND status IN ('accepted', 'flagged')
            GROUP BY round_id, source_id
            ORDER BY round_id, assessments DESC, source_id
            SQL, ['{'.implode(',', $roundIds).'}']);

        foreach ($rows as $row) {
            $roundId = (int) $row->round_id;
            $counts[$roundId]['bySource'][] = new SourceCount((int) $row->source_id, (int) $row->assessments);
            $counts[$roundId]['total'] += (int) $row->assessments;
        }

        return $counts;
    }
}
