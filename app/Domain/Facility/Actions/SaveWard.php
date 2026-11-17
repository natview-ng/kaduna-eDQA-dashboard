<?php

declare(strict_types=1);

namespace App\Domain\Facility\Actions;

use App\Domain\Facility\Exceptions\FacilityActionRefused;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wards within one of the 23 LGAs: add and rename, audited, a name unique in
 * its LGA whatever the case. Wards are in the form's cascading lists, so every change dates the
 * facility media file. There is no LGA add or edit anywhere (hard rule 9).
 */
final class SaveWard
{
    public function create(int $lgaId, string $name, ?User $actor, ?string $reason = null): Ward
    {
        $name = trim($name);

        return DB::transaction(function () use ($lgaId, $name, $actor, $reason): Ward {
            $lga = Lga::query()->findOrFail($lgaId);
            $this->assertFree($lga, $name);

            /** @var Ward $ward */
            $ward = activity()->withoutLogs(fn (): Ward => Ward::query()->create([
                'lga_id' => $lga->id,
                'name' => $name,
                'code' => mb_substr(strtoupper($lga->code).'-'.Str::upper(Str::slug($name, '')), 0, 40),
            ]));

            AuditLog::record($ward, $actor, 'ward_created', old: [], new: ['lga_id' => $lga->id, 'name' => $ward->name, 'code' => $ward->code], reason: $reason, extra: ['cascade' => true]);

            return $ward;
        });
    }

    public function rename(Ward $ward, string $name, User $actor): Ward
    {
        $name = trim($name);

        return DB::transaction(function () use ($ward, $name, $actor): Ward {
            $ward = Ward::query()->lockForUpdate()->findOrFail($ward->id);
            if ($ward->name === $name) {
                return $ward;
            }
            $lga = Lga::query()->findOrFail($ward->lga_id);
            $this->assertFree($lga, $name, $ward->id);

            $old = $ward->name;
            activity()->withoutLogs(fn () => $ward->update(['name' => $name]));
            AuditLog::record($ward, $actor, 'ward_renamed', old: ['name' => $old], new: ['name' => $name], extra: ['cascade' => true]);

            return $ward;
        });
    }

    private function assertFree(Lga $lga, string $name, ?int $except = null): void
    {
        $taken = Ward::query()->where('lga_id', $lga->id)
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->exists();
        if ($taken) {
            throw FacilityActionRefused::wardExists($name, $lga->name);
        }
    }
}
