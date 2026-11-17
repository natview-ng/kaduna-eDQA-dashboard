<?php

declare(strict_types=1);

namespace App\Domain\Facility\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Is the facility media file out of date? The form's cascading lists come from
 * that file, attached by hand on ODK Central and KoboToolbox, so a master-list change that reaches
 * them (an audit entry marked `cascade`: a facility's code, name, ward, LGA or active flag; a ward
 * added or renamed) makes it stale until the next export (`cascade_exported`). Read from the audit
 * log, which every write already goes through (hard rule 7), ordered by id, so a change made in
 * the same second as an export is still after it. Nothing is stored for it.
 */
final class CascadeStatusQuery
{
    /** @return array{out_of_date: bool, changed_at: ?string, exported_at: ?string} */
    public function status(): array
    {
        $change = DB::table('activity_log')->whereRaw("properties->>'cascade' = 'true'")->orderByDesc('id')->first(['id', 'created_at']);
        $export = DB::table('activity_log')->where('event', 'cascade_exported')->orderByDesc('id')->first(['id', 'created_at']);

        return [
            'out_of_date' => $change !== null && ($export === null || (int) $change->id > (int) $export->id),
            'changed_at' => $change === null ? null : CarbonImmutable::parse((string) $change->created_at)->toIso8601String(),
            'exported_at' => $export === null ? null : CarbonImmutable::parse((string) $export->created_at)->toIso8601String(),
        ];
    }
}
