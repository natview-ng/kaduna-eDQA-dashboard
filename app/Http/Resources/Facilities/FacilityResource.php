<?php

declare(strict_types=1);

namespace App\Http\Resources\Facilities;

use App\Domain\Facility\Models\Facility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A master-list facility as the Facility list API returns it after a change (admin only).
 *
 * @mixin Facility
 */
final class FacilityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['ward:id,name', 'lga:id,code,name']);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'ward' => ['id' => $this->ward->id, 'name' => $this->ward->name],
            'lga' => ['id' => $this->lga->id, 'code' => $this->lga->code, 'name' => $this->lga->name],
            'level' => $this->level->value,
            'ownership' => $this->ownership->value,
            'active' => (bool) $this->is_active,
            'lat' => $this->lat === null ? null : (string) $this->lat,
            'lng' => $this->lng === null ? null : (string) $this->lng,
        ];
    }
}
