<?php

declare(strict_types=1);

namespace App\Domain\Facility\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Exceptions\FacilityActionRefused;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Ward;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Adding a facility and editing one inline (ARCHITECTURE.md §9.3): the master
 * list is the validation authority, so every change is audited old and new, with the reason when
 * one is given (the master-list import gives "master list import {file}").
 *
 * - A facility's LGA is its ward's: choosing a ward in another LGA moves the LGA with it.
 * - The code is the key submissions are matched on: it cannot change once any assessment is
 *   recorded under it (any status, withdrawn included: the history refers to it).
 * - A change to what the form's cascading lists show (code, name, ward, LGA, active) marks the
 *   audit entry `cascade`, which is what makes the facility media file out of date
 *   (CascadeStatusQuery).
 */
final class SaveFacility
{
    /** What reaches the form's cascading select (CascadeExporter): a change here dates the media file. */
    public const CASCADE_FIELDS = ['code', 'name', 'ward_id', 'lga_id', 'is_active'];

    /** @param  array{code: string, name: string, ward_id: int, level: string, ownership: string, lat?: float|string|null, lng?: float|string|null}  $data */
    public function create(array $data, ?User $actor, ?string $reason = null, string $event = 'facility_created'): Facility
    {
        return DB::transaction(function () use ($data, $actor, $reason, $event): Facility {
            if (Facility::query()->where('code', $data['code'])->exists()) {
                throw FacilityActionRefused::codeTaken($data['code']);
            }
            $ward = Ward::query()->findOrFail($data['ward_id']);
            $attributes = [
                'code' => $data['code'],
                'name' => $data['name'],
                'ward_id' => $ward->id,
                'lga_id' => $ward->lga_id,
                'level' => $data['level'],
                'ownership' => $data['ownership'],
                'is_active' => true,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ];
            /** @var Facility $facility */
            $facility = activity()->withoutLogs(fn (): Facility => Facility::query()->create($attributes));

            AuditLog::record($facility, $actor, $event, old: [], new: self::snapshot($facility), reason: $reason, extra: ['cascade' => true]);

            return $facility;
        });
    }

    /**
     * @param  array{code?: string, name?: string, ward_id?: int, level?: string, ownership?: string, lat?: float|string|null, lng?: float|string|null}  $changes
     */
    public function update(Facility $facility, array $changes, ?User $actor, ?string $reason = null): Facility
    {
        return DB::transaction(function () use ($facility, $changes, $actor, $reason): Facility {
            $facility = Facility::query()->lockForUpdate()->findOrFail($facility->id);
            $before = self::snapshot($facility);

            if (array_key_exists('code', $changes) && $changes['code'] !== $facility->code) {
                $assessments = Assessment::query()->where('facility_id', $facility->id)->count();
                if ($assessments > 0) {
                    throw FacilityActionRefused::codeLocked($assessments);
                }
                if (Facility::query()->where('code', $changes['code'])->whereKeyNot($facility->id)->exists()) {
                    throw FacilityActionRefused::codeTaken($changes['code']);
                }
            }
            if (array_key_exists('ward_id', $changes)) {
                $changes['lga_id'] = Ward::query()->findOrFail($changes['ward_id'])->lga_id;
            }

            $facility->fill(array_intersect_key($changes, array_flip(['code', 'name', 'ward_id', 'lga_id', 'level', 'ownership', 'lat', 'lng'])));
            $after = self::snapshot($facility);
            $changed = array_keys(array_diff_assoc(array_map(strval(...), $after), array_map(strval(...), $before)));
            if ($changed === []) {
                return $facility;
            }
            activity()->withoutLogs(fn () => $facility->save());

            AuditLog::record($facility, $actor, 'facility_updated',
                old: array_intersect_key($before, array_flip($changed)),
                new: array_intersect_key($after, array_flip($changed)),
                reason: $reason,
                extra: ['cascade' => array_intersect($changed, self::CASCADE_FIELDS) !== []],
            );

            return $facility;
        });
    }

    /**
     * The audited view of a facility: plain values, so old and new compare as written.
     *
     * @return array<string, string|int|bool|null>
     */
    public static function snapshot(Facility $facility): array
    {
        return [
            'code' => $facility->code,
            'name' => $facility->name,
            'ward_id' => (int) $facility->ward_id,
            'lga_id' => (int) $facility->lga_id,
            'level' => $facility->level->value,
            'ownership' => $facility->ownership->value,
            'is_active' => (bool) $facility->is_active,
            'lat' => $facility->lat === null ? null : (string) $facility->lat,
            'lng' => $facility->lng === null ? null : (string) $facility->lng,
        ];
    }
}
