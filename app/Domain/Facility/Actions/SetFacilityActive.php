<?php

declare(strict_types=1);

namespace App\Domain\Facility\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Exceptions\FacilityActionRefused;
use App\Domain\Facility\Models\Facility;
use App\Domain\Round\Enums\RoundStatus;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Deactivate (never delete) and reactivate a facility, each with a reason (Phase 5 step 5).
 *
 * A deactivated facility leaves the form's lists (the media file goes out of date) and any new
 * submission for it, from either source, quarantines under FACILITY_UNKNOWN; what it already has
 * stays, and its published history stays on the public dashboard (reporting does not read
 * is_active). The audit entry records how many of its assessments sit in an open round, which is
 * what the page warns about before it asks.
 */
final class SetFacilityActive
{
    public const MIN_REASON = 10;

    public function deactivate(Facility $facility, ?User $actor, string $reason): Facility
    {
        return $this->set($facility, false, $actor, $reason);
    }

    public function reactivate(Facility $facility, User $actor, string $reason): Facility
    {
        return $this->set($facility, true, $actor, $reason);
    }

    private function set(Facility $facility, bool $active, ?User $actor, string $reason): Facility
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw FacilityActionRefused::reasonTooShort();
        }

        return DB::transaction(function () use ($facility, $active, $actor, $reason): Facility {
            $facility = Facility::query()->lockForUpdate()->findOrFail($facility->id);
            if ($facility->is_active === $active) {
                throw $active ? FacilityActionRefused::notInactive() : FacilityActionRefused::alreadyInactive();
            }

            activity()->withoutLogs(fn () => $facility->update(['is_active' => $active]));

            AuditLog::record($facility, $actor, $active ? 'facility_reactivated' : 'facility_deactivated',
                old: ['is_active' => ! $active],
                new: ['is_active' => $active],
                reason: $reason,
                extra: [
                    'cascade' => true,
                    'open_round_assessments' => Assessment::query()->where('facility_id', $facility->id)
                        ->whereHas('round', fn ($q) => $q->where('status', RoundStatus::Open))->count(),
                ],
            );

            return $facility;
        });
    }
}
