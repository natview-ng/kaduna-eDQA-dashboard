<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Which sources a facility's counted assessments came from in the given rounds (the facility scores
 * export's source column, Phase 6 step 1). Reads assessments.source_id only, as the Monitor's
 * assessments-by-source does: never submissions. The caller names the sources.
 */
final class FacilitySourcesQuery
{
    /**
     * @param  list<int>  $facilityIds
     * @param  list<int>  $roundIds
     * @return array<int, list<int>> facility id => source ids, in id order
     */
    public function forFacilities(array $facilityIds, array $roundIds): array
    {
        if ($facilityIds === [] || $roundIds === []) {
            return [];
        }
        $sources = [];
        $rows = DB::table('assessments')->whereIn('facility_id', $facilityIds)->whereIn('round_id', $roundIds)
            ->whereIn('status', ['accepted', 'flagged'])->distinct()->orderBy('facility_id')->orderBy('source_id')->get(['facility_id', 'source_id']);
        foreach ($rows as $row) {
            $sources[(int) $row->facility_id][] = (int) $row->source_id;
        }

        return $sources;
    }
}
