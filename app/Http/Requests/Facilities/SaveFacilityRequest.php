<?php

declare(strict_types=1);

namespace App\Http\Requests\Facilities;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Facility\Models\Facility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/facilities (every field) and PATCH /api/facilities/{facility} (any of them): a
 * facility of the master list. The code is unique; that it is locked once assessed is the
 * domain's rule (SaveFacility), refused as 409 code_locked.
 */
final class SaveFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $facility = $this->route('facility');
        $creating = ! $facility instanceof Facility;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/\.]*$/', Rule::unique('facilities', 'code')->ignore($creating ? null : $facility->id)],
            'name' => [$required, 'string', 'min:3', 'max:200'],
            'ward_id' => [$required, 'integer', 'exists:wards,id'],
            'level' => [$required, Rule::enum(FacilityLevel::class)],
            'ownership' => [$required, Rule::enum(Ownership::class)],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array{code?: string, name?: string, ward_id?: int, level?: string, ownership?: string, lat?: float|string|null, lng?: float|string|null} */
    public function changes(): array
    {
        /** @var array{code?: string, name?: string, ward_id?: int, level?: string, ownership?: string, lat?: float|string|null, lng?: float|string|null} $valid */
        $valid = $this->validated();
        if (isset($valid['name'])) {
            $valid['name'] = trim($valid['name']);
        }
        if (isset($valid['code'])) {
            $valid['code'] = trim($valid['code']);
        }

        return $valid;
    }
}
